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
use MerchantQuoteAgentPlugin\Negotiation\AskInterpreter;
use MerchantQuoteAgentPlugin\Negotiation\MarginFloorGuard;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationPipeline;
use MerchantQuoteAgentPlugin\Negotiation\OfferApplier;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\OfferRound;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
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
 *
 * @mago-expect lint:too-many-methods
 * The private scenario builders (`round()`, `snapshotWith()`, `gateway()`,
 * `settings()`, `decision()`) are shared fixtures, not test cases of their
 * own, and every `test*` method pins a distinct end-to-end fact -- including
 * #174/#175's never-raise escalation and hold-reply cases. Splitting the
 * class would either duplicate those fixtures or scatter one round's
 * coverage across two files for no reader's benefit.
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
            new OfferApplier(new OfferVerifier(), $logger, $recorder, new MarginFloorGuard(new FakePurchasePrices())),
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
        // Issue #169: the model DID propose a per-line offer here; the system
        // declines to apply it for want of a baseline to bound it against,
        // which is a policy-layer refusal to authorize the proposal, not an
        // unspecified "needs a human" catch-all.
        self::assertSame(QuoteEscalationReason::ProposalRejected, $pass->escalationReason);
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

    /**
     * Issue #174, quote 1039's exact shape, end to end through the round: a
     * per-line — here quote-wide — offer priced from the baseline (2114.56)
     * lands above the quote's current total (1818.20). Every EXISTING check
     * (baseline discount cap, line bounds, expiry) reads this as a clean 5%
     * discount; only the never-raise check catches it. The pass must
     * escalate, and the false "discount" must never reach the buyer.
     */
    public function testANeverRaiseViolationEscalatesTheWholePassQuote1039Shape(): void
    {
        $recorder = new DecisionRecorder(new FakeDecisionWriter());
        $logger = new RecordingLogger();
        $offerReply = '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}';
        [$client] = ScriptedClient::spy([$offerReply, 'a rewording that keeps none of the facts']);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');

        $round = new OfferRound(
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder, new FakeCustomerHistoryFactory()),
            new OfferApplier(new OfferVerifier(), $logger, $recorder, new MarginFloorGuard(new FakePurchasePrices())),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            new QuoteEscalator(),
            $logger,
        );

        $snapshot = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(totalNet: 1818.20, comments: [
                NegotiationFixture::buyerComment('Nice, thanks!', '2026-09-18 09:58:40'),
            ]),
            NegotiationFixture::baselineOf(2114.56, 211.456),
        );
        $gateway = new FakeQuoteGateway([$snapshot, NegotiationFixture::snapshot(totalNet: 2008.83)]);

        $pass = $round->play($gateway, $snapshot, self::settings(), self::decision(), null);

        self::assertSame(NegotiationOutcome::Escalated, $pass->outcome);
        self::assertNotContains('addComment', $gateway->calls, 'The false discount must never reach the buyer.');
    }

    /**
     * Issues #174 (second requirement) and #175, quote 1045's exact shape:
     * the write changes nothing (34000.00 -> 34000.00) even though the stored
     * baseline (34456.73) differs from the current total. The reply must
     * state the hold, never a baseline percentage the pass did not grant.
     */
    public function testAPassThatGrantsNothingReportsAHoldNotABaselinePercentageQuote1045Shape(): void
    {
        $recorder = new DecisionRecorder(new FakeDecisionWriter());
        $logger = new RecordingLogger();
        $offerReply = '{"action":"offer","message":"Holding at the current price.","terms":{"discountPercent":0}}';
        [$client] = ScriptedClient::spy([$offerReply, 'a rewording that keeps none of the facts']);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');

        $round = new OfferRound(
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder, new FakeCustomerHistoryFactory()),
            new OfferApplier(new OfferVerifier(), $logger, $recorder, new MarginFloorGuard(new FakePurchasePrices())),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            new QuoteEscalator(),
            $logger,
        );

        $baseline = NegotiationFixture::baselineOf(34456.73, 3445.673);
        $snapshot = NegotiationFixture::withCustomFields(NegotiationFixture::snapshot(totalNet: 34000.0, comments: [
            NegotiationFixture::buyerComment('Can you still do better?', '2026-09-18 11:54:00'),
        ]), $baseline);
        $gateway = new FakeQuoteGateway([
            $snapshot,
            NegotiationFixture::withCustomFields(
                NegotiationFixture::snapshot(state: 'in_review', totalNet: 34000.0),
                $baseline,
            ),
        ]);

        $pass = $round->play($gateway, $snapshot, self::settings(), self::decision(), null);

        self::assertSame(NegotiationOutcome::Offered, $pass->outcome);
        self::assertStringContainsString('This quote stands at 34000.00 EUR', $gateway->comments[0]);
        self::assertStringNotContainsString('%', $gateway->comments[0]);
    }

    /**
     * The negative-reduction shape, constructed directly at the
     * `ReplyTemplate::reduction()` call site: the never-raise check in
     * `OfferApplier` compares the write against the quote's CURRENT total and
     * passes clean, `DiscountTotalViolation` compares it against the
     * baseline OfferApplier itself anchors on and also passes clean, yet
     * `reduction()` -- fed `SnapshotAdapter::anchored($snapshot)` from the
     * PASS-START snapshot -- still sees an increase, because that pass-start
     * snapshot predates line-2 entirely (not merely a stale stored fragment;
     * #54's re-extension has nothing to re-extend FROM). A line added
     * between the pass-start read and OfferApplier's own pre-write read is
     * exactly the race #54 already documents elsewhere in this class.
     *
     * The pass MUST escalate through the same funnel a verification failure
     * uses, not let the exception reach the caller: `NegativeReduction` is
     * unchecked, so uncaught it would leave `NegotiationPipeline::run()`'s
     * handled cases, and `ServiceQuoteHandler` would rethrow it for Messenger
     * to redeliver against a quote whose write already landed, with no reply
     * ever sent.
     */
    public function testANegativeReductionAtTheCallSiteEscalatesRatherThanPropagating(): void
    {
        $recorder = new DecisionRecorder(new FakeDecisionWriter());
        $logger = new RecordingLogger();
        [$client] = ScriptedClient::spy([self::STALE_ANCHOR_OFFER_REPLY, 'a rewording that keeps none of the facts']);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');

        $round = new OfferRound(
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder, new FakeCustomerHistoryFactory()),
            new OfferApplier(new OfferVerifier(), $logger, $recorder, new MarginFloorGuard(new FakePurchasePrices())),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            new QuoteEscalator(),
            $logger,
        );

        [$snapshot, $reference, $after] = self::staleAnchorScenario();
        $gateway = new FakeQuoteGateway([$reference, $after]);

        $pass = $round->play($gateway, $snapshot, self::settings(), self::decision(), null);

        self::assertSame(
            NegotiationOutcome::Escalated,
            $pass->outcome,
            'A negative reduction must escalate the pass, not propagate out of play().',
        );
        self::assertSame(QuoteEscalationReason::VerificationFailed, $pass->escalationReason);
        self::assertNotContains(
            'addComment',
            $gateway->calls,
            'No reply may be composed once the figure to report disagreed with the write.',
        );
    }

    /**
     * The same shape, one layer further out: through
     * `NegotiationPipeline::service()`, the boundary `ServiceQuoteHandler`
     * actually calls. `NegativeReduction` is not `ModelUnavailable` or
     * `CrossCustomerRead`, so if `OfferRound` did not catch it, it would
     * leave `run()`'s handled cases uncaught and `service()` would rethrow it
     * for `ServiceQuoteHandler` to redeliver against a quote whose write
     * already landed. This pins that `service()` instead returns normally.
     */
    public function testANegativeReductionDoesNotEscapeNegotiationPipelineService(): void
    {
        $recorder = new DecisionRecorder(new FakeDecisionWriter());
        $logger = new RecordingLogger();
        [$client] = ScriptedClient::spy([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","quantity":null,"targetUnitPrice":95,"remove":false}]}}',
            self::STALE_ANCHOR_OFFER_REPLY,
            'a rewording that keeps none of the facts',
        ]);
        $prompts = new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}');
        $round = new OfferRound(
            new OfferProposer($client, $prompts, new OfferAuthorizer(), $recorder, new FakeCustomerHistoryFactory()),
            new OfferApplier(new OfferVerifier(), $logger, $recorder, new MarginFloorGuard(new FakePurchasePrices())),
            new ReplyComposer($client, $prompts, $logger, $recorder),
            new QuoteEscalator(),
            $logger,
        );
        $pipeline = new NegotiationPipeline(
            new AskInterpreter($client, $prompts, $recorder),
            new NegotiationDecider(),
            $round,
            $recorder,
            $logger,
        );

        [$snapshot, $reference, $after] = self::staleAnchorScenario();
        $gateway = new FakeQuoteGateway([$reference, $after]);

        $outcome = $pipeline->service($snapshot, $gateway, self::settings(), NegotiationFixture::context());

        self::assertSame(NegotiationOutcome::Escalated, $outcome);
    }

    private const STALE_ANCHOR_OFFER_REPLY = '{"action":"offer","message":"95 each.","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":95}]}}';

    /**
     * The stale-anchor race behind both tests above: line-2 exists on the
     * real quote (`$reference`, `$after`) but not on the pass-start snapshot
     * (`$snapshot`) `OfferRound` reads its reduction baseline from — see the
     * escalation test's own docblock for why that produces a negative
     * reduction while every other check passes clean.
     *
     * @return array{0: QuoteSnapshot, 1: QuoteSnapshot, 2: QuoteSnapshot}
     */
    private static function staleAnchorScenario(): array
    {
        $identity = new QuoteIdentity('q1', '10001', 'EUR', 'sc1', 'cust-1');
        $revision = new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00'));
        $baseline = NegotiationFixture::baselineOf(1000.0, 100.0);
        $comments = [
            NegotiationFixture::buyerComment('Can you do 95 on the widget?', '2026-08-28 09:00:00'),
        ];
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

        // Pass-start: line-1 only. This is what OfferProposer authorizes
        // against and what SnapshotAdapter::anchored($snapshot) in OfferRound
        // reads -- its anchor is the stored baseline exactly, 1000.00,
        // because it has no line-2 to extend with at all.
        $snapshot = new QuoteSnapshot(
            identity: $identity,
            revision: $revision,
            totals: new QuoteTotals(totalNet: 1000.0, totalGross: 1000.0),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'open',
                expiresAt: new \DateTimeImmutable(NegotiationFixture::expires()),
                customFields: $baseline,
            ),
            content: new QuoteContent(lines: [$lineOne], comments: $comments),
        );

        // OfferApplier's own pre-write read: line-2 already exists on the
        // real quote by the time this pass writes. Its anchor extends to
        // 1000 + 200 = 1200.00 (#54's own in-memory re-extension), so both
        // the never-raise check and DiscountTotalViolation measure against
        // 1200.00 and see nothing wrong with landing at 1150.00.
        $reference = new QuoteSnapshot(
            identity: $identity,
            revision: $revision,
            totals: new QuoteTotals(totalNet: 1200.0, totalGross: 1200.0),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'open',
                expiresAt: new \DateTimeImmutable(NegotiationFixture::expires()),
                customFields: $baseline,
            ),
            content: new QuoteContent(lines: [$lineOne, $lineTwo], comments: $comments),
        );

        // After the write: line-1 cut to 95, line-2 untouched. 950 + 200 =
        // 1150.00 -- BELOW the 1200.00 both checks measured against, and
        // ABOVE the 1000.00 reduction() is fed from the pass-start snapshot.
        $after = new QuoteSnapshot(
            identity: $identity,
            revision: $revision,
            totals: new QuoteTotals(totalNet: 1150.0, totalGross: 1150.0),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'in_review',
                expiresAt: new \DateTimeImmutable(NegotiationFixture::expires()),
                customFields: $baseline,
            ),
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Widget'),
                    quantity: 10,
                    unitPriceNet: 95.0,
                    totalNet: 950.0,
                ),
                $lineTwo,
            ], comments: $comments),
        );

        return [$snapshot, $reference, $after];
    }

    public function testEveryPassEndsInOneStructuredEventCarryingThePromptHashes(): void
    {
        // The hashes are how #22 attributes an outcome to the prompt versions
        // that produced it, and #19 reads exactly this event.
        $harness = PipelineHarness::with([
            '{"price":{"additionalDiscountPercent":5}}',
            '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
            PipelineHarness::rewordedReply(),
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
