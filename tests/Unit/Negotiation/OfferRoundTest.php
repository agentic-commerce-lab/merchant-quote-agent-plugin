<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use MerchantQuoteAgentPlugin\Policy\QuoteBandDecider;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;

/**
 * One round, end to end over the wired pipeline: what it writes back to the
 * buyer, what it refuses to write twice, and what it reports about itself.
 */
final class OfferRoundTest extends TestCase
{
    /**
     * An OfferRound whose scripted model answer is per-line or quote-wide, on
     * request, plus the logger it was built with, so a test can pin an
     * escalation to the guard's own log message rather than just its outcome.
     *
     * @return array{0: OfferRound, 1: RecordingLogger}
     */
    private static function round(bool $perLineOffer): array
    {
        $recorder = new DecisionRecorder(new FakeDecisionWriter());
        $logger = new RecordingLogger();
        $offerReply = $perLineOffer
            ? '{"action":"offer","message":"95 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":95}]}}'
            : '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}';
        [$client] = ScriptedClient::spy([$offerReply, 'a rewording that keeps none of the facts']);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');

        $round = new OfferRound(
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder, new FakeCustomerHistoryFactory()),
            new OfferApplier(new OfferVerifier(), $logger, $recorder),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            new QuoteEscalator(),
            $logger,
        );

        return [$round, $logger];
    }

    /** @param array<string, mixed> $customFields */
    private static function snapshotWith(bool $agentComment, array $customFields): QuoteSnapshot
    {
        $comments = $agentComment
            ? [
                NegotiationFixture::agentComment('Our first offer.', '2026-08-28 09:00:00'),
                NegotiationFixture::buyerComment('Still too high.', '2026-08-28 10:00:00'),
            ]
            : [];

        return NegotiationFixture::withCustomFields(NegotiationFixture::snapshot(comments: $comments), $customFields);
    }

    private static function gateway(): FakeQuoteGateway
    {
        return new FakeQuoteGateway([NegotiationFixture::snapshot(state: 'in_review')]);
    }

    private static function settings(): QuoteAgentSettings
    {
        return NegotiationFixture::settings();
    }

    /** A grant-band decision: the buyer asked 5% against a 10% cap. */
    private static function decision(): NegotiationDecision
    {
        $snapshot = SnapshotAdapter::toPolicy(NegotiationFixture::snapshot());
        $price = (new QuoteBandDecider())->decide(
            $snapshot->withBuyerTargetNet(950.0),
            NegotiationFixture::settings()->policy->price,
        );

        return new NegotiationDecision(Band::Grant, $price);
    }

    public function testAPerLineOfferTellsTheBuyerWhatTheQuoteActuallyCameDownBy(): void
    {
        // A per-line offer carries no `discountPercent` — deliberately, in both
        // modes — so the reply used to announce "0% off this quote" on a quote
        // whose line prices had just been cut. The figure the buyer is told is
        // the one the database reports: 1000.00 before, 950.00 after.
        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":null,"targetUnitPrice":95,"remove":false}]}}',
            '{"action":"offer","message":"95 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":95}]}}',
            'a rewording that keeps none of the facts',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('95 per unit?', '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertStringContainsString('down by 5% to 950.00 EUR', $harness->gateway->comments[0]);
        self::assertStringNotContainsString('0%', $harness->gateway->comments[0]);
    }

    /**
     * #49 removed the blanket second-round escalation, but a quote serviced
     * before the baseline existed still has no anchor, so a per-line offer on
     * it would be measured against already-reduced prices. Those keep
     * escalating until they close.
     */
    public function testAPerLineOfferOnAQuoteServicedBeforeTheBaselineExistedStillEscalates(): void
    {
        [$round, $logger] = self::round(perLineOffer: true);
        $snapshot = self::snapshotWith(agentComment: true, customFields: [
            ServicingFingerprint::MARKER_KEY => 'some-old-stamp',
        ]);

        $pass = $round->play(self::gateway(), $snapshot, self::settings(), self::decision(), null);

        self::assertSame(NegotiationOutcome::Escalated, $pass->outcome);
        self::assertNotNull(
            $logger->contextOf('no stored baseline'),
            'Escalated, but not via the missing-baseline guard — some other refusal fired instead.',
        );
    }

    /** With a baseline present, round two is answered rather than handed to a human. */
    public function testAPerLineOfferOnALaterRoundIsAnsweredOnceTheQuoteHasABaseline(): void
    {
        [$round] = self::round(perLineOffer: true);
        $snapshot = self::snapshotWith(agentComment: true, customFields: [
            ServicingFingerprint::MARKER_KEY => 'some-old-stamp',
            QuoteBaseline::KEY => [
                'totalNet' => 1000.0,
                'lines' => [['lineItemId' => 'line-1', 'unitPriceNet' => 100.0, 'quantity' => 10]],
            ],
        ]);

        $pass = $round->play(self::gateway(), $snapshot, self::settings(), self::decision(), null);

        self::assertNotSame(
            NegotiationOutcome::Escalated,
            $pass->outcome,
            'A per-line round two with a baseline was escalated: the stopgap is still in place.',
        );
    }

    /**
     * IMPORTANT. `$context->baseline` is read from the pass-START snapshot,
     * whose custom fields predate ServiceQuoteHandler::claimAttempt()'s
     * extension — the exact staleness anchor() exists to compensate for. On
     * the pass where a line first appears, the un-extended baseline still
     * says 1000 while the quote (with the new line) actually opened this
     * pass at 1200; a 5% cut lands the total at 1140, and the buyer must be
     * told 5%, not "0% to 1140.00 EUR" from reduction(1000, 1140) flooring at
     * zero.
     */
    public function testTheReplyMeasuresTheReductionAgainstTheExtendedBaselineNotTheStaleOne(): void
    {
        [$round] = self::round(perLineOffer: false);

        $lineOne = new QuoteLineSnapshot(
            identity: new QuoteLineIdentity('line-1', 'Widget'),
            quantity: 10,
            unitPriceNet: 100.0,
            totalNet: 1000.0,
        );
        $lineTwo = new QuoteLineSnapshot(
            identity: new QuoteLineIdentity('line-2', 'Gizmo'),
            quantity: 1,
            unitPriceNet: 200.0,
            totalNet: 200.0,
        );
        $comments = [
            NegotiationFixture::agentComment('Our first offer.', '2026-08-28 09:00:00'),
            NegotiationFixture::buyerComment('Still too high.', '2026-08-28 10:00:00'),
        ];

        // The pass-start snapshot: the buyer's line was already added, but
        // the stored baseline (from the FIRST pass, before line-2 existed)
        // only knows line-1 — exactly what claimAttempt() leaves behind for
        // the in-memory snapshot the pipeline was handed.
        $snapshot = new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1', 'cust-1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00')),
            totals: new QuoteTotals(totalNet: 1200.0, totalGross: 1200.0),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'open',
                expiresAt: new \DateTimeImmutable(NegotiationFixture::expires()),
                customFields: NegotiationFixture::baselineOf(1000.0, 100.0),
            ),
            content: new QuoteContent(lines: [$lineOne, $lineTwo], comments: $comments),
        );
        $after = new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: new QuoteTotals(totalNet: 1140.0, totalGross: 1140.0),
            lifecycle: $snapshot->lifecycle,
            content: $snapshot->content,
        );

        $gateway = new FakeQuoteGateway([$snapshot, $after]);
        $round->play($gateway, $snapshot, self::settings(), self::decision(), null);

        self::assertStringContainsString('down by 5% to 1140.00 EUR', $gateway->comments[0]);
        self::assertStringNotContainsString('0%', $gateway->comments[0]);
    }

    public function testEveryPassEndsInOneStructuredEventCarryingThePromptHashes(): void
    {
        // The hashes are how #22 attributes an outcome to the prompt versions
        // that produced it, and #19 reads exactly this event.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            'We can bring this quote down by 5% to 950.00 EUR. The offer is valid until '
                . NegotiationFixture::expires()
                . '.',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );
        $context = $harness->logger->contextOf('pass finished');

        self::assertNotNull($context, 'Every pass must emit one structured event.');
        self::assertSame('offered', $context['outcome']);
        self::assertSame('q1', $context['quoteId']);
        self::assertSame('sc1', $context['salesChannelId']);

        foreach (['extractPromptHash', 'negotiatePromptHash', 'replyPromptHash'] as $key) {
            self::assertIsString($context[$key], $key . ' must reach the log.');
            self::assertNotSame('', $context[$key]);
        }
    }
}
