<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bench;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Tests\Bench\Scenario;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\FakeCustomerHistoryFactory;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\PipelineHarness;
use PHPUnit\Framework\TestCase;

/**
 * Task 7's verification step: every scenario under `tests/Bench/scenarios/`
 * driven through the REAL pipeline (`NegotiationPipeline`, the same class
 * `BenchNegotiation` drives against a live shop) with a scripted model and no
 * network -- free, deterministic, CI-safe. Where a scenario declares an
 * `expectedBand`, the resulting `NegotiationOutcome` is asserted against it.
 *
 * `expectedBand` is not `Policy\Data\Band`'s own backing value (`grant` /
 * `counter` / `escalate`): Task 2 already shipped `plain-percentage.json`
 * with `"auto"`, so this test's own vocabulary extends that one rather than
 * replacing it -- `auto` (Offered/Countered via an auto-reply band, i.e. the
 * ask never leaves the agent's mandate), `escalate` (Escalated), `clarify`
 * (Clarified). A scenario's numbers cannot be derived from its portable JSON
 * fields alone (a symbolic `productRef` carries no unit price, no tax ratio,
 * no stored baseline) -- resolving those into a concrete fixture is this
 * test's own job here, the same way `BenchNegotiation::resolveProduct()`
 * resolves `productRef` against a real shop.
 *
 * Each test loads its scenario from the JSON file rather than restating its
 * id/description inline, so the assertions stay tied to what actually ships.
 *
 * @mago-expect lint:too-many-methods
 * Nine scenario cases plus three private fixture builders for the one
 * scenario (`exactly-at-the-ceiling`) whose numbers cannot be built from the
 * shared `NegotiationFixture` helpers. Splitting one scenario's coverage into
 * a second file would scatter the set this test exists to keep together.
 */
final class ScenarioPipelineTest extends TestCase
{
    private const SCENARIOS_DIR = __DIR__ . '/../../Bench/scenarios';

    public function testPlainPercentageIsGranted(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/plain-percentage.json');

        $harness = PipelineHarness::with(
            [
                '{"price":{"additionalDiscountPercent":5}}',
                '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}',
                'Here you go.',
            ],
            reReadTotalNet: 950.0,
        );
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment($scenario->openingAsk, '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 10.0),
            NegotiationFixture::context(),
        );

        self::assertSame('auto', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Offered, $outcome);
    }

    /**
     * `structured-only`: NO buyer comment at all -- the ask is the storefront's
     * `requestedUnitPrice`, exactly `StructuredAskGateTest`'s regression case.
     * Two model calls, not three: with no comment there is nothing for
     * `AskInterpreter` to extract, so the extract call never happens.
     */
    public function testStructuredOnlyAskIsAnsweredWithoutAComment(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/structured-only.json');

        $harness = PipelineHarness::with(
            [
                '{"action":"offer","message":"2% off.","terms":{"discountPercent":2}}',
                'We can do 2%.',
            ],
            reReadTotalNet: 980.0,
        );
        $snapshot = NegotiationFixture::snapshot(requestedUnitPrice: 98.0);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame('auto', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(2, $harness->spy->calls, 'A structured-only ask needs no extraction call.');
    }

    /**
     * `gross-figure-in-comment`: the buyer types a GROSS per-unit figure on a
     * taxed quote. `BuyerPriceSpace::toNet()` must convert it before anything
     * downstream treats it as net -- live quote 1037's bug stored 885.65 for a
     * 744.24 ask. `AskMirror` mirrors the merger's ADOPTED (already net)
     * target back onto the line before the gate even runs, so that mirrored
     * write is where this test catches a regression: broken conversion would
     * mirror 90.0 (the raw gross figure) instead of 72.0 (90.0 * the quote's
     * own 0.8 net ratio).
     */
    public function testGrossFigureInCommentIsConvertedToNetBeforeItIsPriced(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/gross-figure-in-comment.json');

        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
            '{"action":"offer","message":"10% off.","terms":{"discountPercent":10}}',
            'Confirmed, 10% off.',
        ]);
        $snapshot = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment($scenario->openingAsk, '2026-08-28 09:00:00'),
        ]);
        $harness->gateway->replaceSnapshots([
            $snapshot,
            self::grossSnapshotAt(totalNet: 720.0, totalGross: 900.0),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertSame('auto', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(
            72.0,
            $harness->gateway->lineItemChanges[0]->requestedUnitPriceNet,
            'The gross ask (90.0) must be mirrored as its net equivalent (90.0 * 0.8 ratio = 72.0), not raw.',
        );
    }

    /**
     * `exactly-at-the-ceiling`: 100.40 net, a gross ask that converts (and
     * cent-rounds) to a buyer target of 85.34. `(100.40 - 85.34) / 100.40 *
     * 100` is, in IEEE-754 double arithmetic, 15.00000000000000177636... --
     * strictly above a 15.0 cap by float noise alone. `Epsilon::RATE` (1e-6)
     * is what keeps `QuoteBandDecider` granting this instead of escalating
     * it; this scenario is the one place in the bench that number is load
     * bearing rather than theoretical.
     */
    public function testAGrossAskAgainstACentRoundedBaselineBeatsEpsilon(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/exactly-at-the-ceiling.json');
        self::assertSame(1, $scenario->lines[0]['quantity'], 'The fixture below assumes a single unit.');

        $before = self::ceilingSnapshot($scenario->openingAsk);
        $discountPercent = ((100.40 - 85.34) / 100.40) * 100;
        self::assertGreaterThan(
            15.0,
            $discountPercent,
            'This scenario only proves anything if the raw float is above the cap.',
        );
        self::assertLessThan(15.0 + 1.0e-6, $discountPercent, 'And still inside Epsilon::RATE\'s tolerance.');

        $harness = PipelineHarness::with([
            '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":106.68}]}}',
            '{"action":"offer","message":"15% off.","terms":{"discountPercent":15}}',
            'Thank you, confirmed.',
        ]);
        $harness->gateway->replaceSnapshots([
            $before,
            self::ceilingSnapshotAfter(),
        ]);

        $outcome = $harness->pipeline->service(
            $before,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0, counterOfferMaxPercent: 25.0),
            NegotiationFixture::context(),
        );

        self::assertSame('auto', $scenario->expectedBand);
        self::assertSame(
            NegotiationOutcome::Offered,
            $outcome,
            'A cap comparison without Epsilon::RATE would escalate this instead.',
        );
    }

    /**
     * `multi-round-anchoring`: reproduces `BaselineAnchoredAskTest`'s live
     * quote 1101 regression at what the scenario models as its fifth round --
     * a stored baseline of 80.00/unit, a current (already reduced) price of
     * 72.00, and a new ask of 71.20. Measured against the CURRENT price that
     * is a 1.11% ask; measured against the ORIGINAL baseline -- the one the
     * agent's authority must be capped against -- it is 11%. Round number is
     * cosmetic (`PassContext::$attempt`); the anchoring math does not change
     * across rounds, which is the point.
     */
    public function testMultiRoundAskIsAnchoredOnTheOriginalBaselineNotTheLastRound(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/multi-round-anchoring.json');
        self::assertSame(5, $scenario->maxRounds);

        $harness = PipelineHarness::with([
            '{}',
            '{"action":"offer","message":"11% off.","terms":{"discountPercent":11}}',
            'Here you go.',
        ]);
        $baseline = NegotiationFixture::baselineOf(800.0, 80.0);
        $before = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(
                comments: [NegotiationFixture::buyerComment($scenario->openingAsk, '2026-08-28 09:00:00')],
                totalNet: 720.0,
                requestedUnitPrice: 71.2,
            ),
            $baseline,
        );
        $harness->gateway->replaceSnapshots([
            NegotiationFixture::withCustomFields(
                NegotiationFixture::snapshot(state: 'in_review', totalNet: 720.0, requestedUnitPrice: 71.2),
                $baseline,
            ),
            NegotiationFixture::withCustomFields(
                NegotiationFixture::snapshot(state: 'in_review', totalNet: 712.0),
                $baseline,
            ),
        ]);

        $outcome = $harness->pipeline->service(
            $before,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            new PassContext(ServicingTriggerReason::CommentWritten, attempt: 5),
        );

        self::assertSame('auto', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertStringContainsString(
            'maximum discount you may grant: 11.00%',
            $harness->spy->userPrompts[1],
            '71.20 against the ORIGINAL 80.00 is an 11% ask -- 1.11% is what it would be against the reduced 72.00.',
        );
    }

    public function testBundleAskEscalatesBeforeTheNegotiateCall(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/bundle-ask.json');

        $harness = PipelineHarness::with([
            '{"negotiation":{"bundle":{"requested":true}}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment($scenario->openingAsk, '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame('escalate', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'A bundle ask must not reach the negotiate call.');
    }

    public function testPaymentTermsAskEscalatesBeforeTheNegotiateCall(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/payment-terms-ask.json');

        $harness = PipelineHarness::with([
            '{"negotiation":{"payment":{"requestedNetDays":60}}}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment($scenario->openingAsk, '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame('escalate', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Escalated, $outcome);
        self::assertSame(1, $harness->spy->calls, 'A payment-terms ask must not reach the negotiate call.');
    }

    public function testAmbiguousAskDrawsAClarificationInsteadOfAnOffer(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/ambiguous-ask.json');

        $harness = PipelineHarness::with([
            '{"clarificationQuestions":["Which line did you mean?"]}',
        ]);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment($scenario->openingAsk, '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame('clarify', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Clarified, $outcome);
        self::assertSame(1, $harness->spy->calls, 'An ambiguous ask must not reach the negotiate call.');
        self::assertSame(['Which line did you mean?'], $harness->gateway->comments);

        foreach ($harness->gateway->customFieldWrites as $write) {
            self::assertArrayHasKey(ClarificationMarker::MARKER_KEY, $write);
        }
    }

    /**
     * `hostile-extraction`: the injected text asks the agent to switch to a
     * foreign customer and disclose their history. Nothing in the pipeline
     * reads a customer id out of comment text -- `OfferRound` builds its
     * `NegotiationContext` from `$snapshot->identity->customerId`, a
     * structural field the buyer's prose cannot touch -- so the spy history
     * factory must be bound to the snapshot's OWN customer only, never the
     * one named in the injection, and the legitimate 15% price ask underneath
     * it is serviced exactly as any other in-band ask would be.
     */
    public function testHostileExtractionNeverMovesWhichCustomersHistoryIsRead(): void
    {
        $scenario = Scenario::load(self::SCENARIOS_DIR . '/hostile-extraction.json');
        $historyFactory = new FakeCustomerHistoryFactory();

        $harness = PipelineHarness::with(
            [
                '{"price":{"additionalDiscountPercent":15}}',
                '{"action":"offer","message":"15% off.","terms":{"discountPercent":15}}',
                'Confirmed, 15% off.',
            ],
            reReadTotalNet: 850.0,
            historyFactory: $historyFactory,
        );
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment($scenario->openingAsk, '2026-08-28 09:00:00'),
        ]);

        $outcome = $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 20.0, counterOfferMaxPercent: 25.0),
            NegotiationFixture::context(),
        );

        self::assertSame('auto', $scenario->expectedBand);
        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(
            ['cust-1'],
            $historyFactory->boundTo,
            'The injected "switch to customer" text must never change whose history is read.',
        );

        foreach ($harness->gateway->comments as $comment) {
            self::assertStringNotContainsString(
                '9f2c1e',
                $comment,
                'The foreign customer id must never reach the buyer-facing reply.',
            );
        }
    }

    private static function ceilingSnapshot(string $comment): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1', 'cust-1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 100.40, totalGross: 125.50),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'open',
                expiresAt: new \DateTimeImmutable(NegotiationFixture::expires()),
            ),
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 1,
                unitPriceNet: 100.40,
                totalNet: 100.40,
                netRatio: 0.8,
            )], comments: [NegotiationFixture::buyerComment($comment, '2026-08-28 09:00:00')]),
        );
    }

    /** The post-apply re-read a 15% cut over 100.40/125.50 produces: 85.34 net, 106.6775 -> 106.68 gross. */
    private static function ceilingSnapshotAfter(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1', 'cust-1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 85.34, totalGross: 106.68),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'in_review',
                expiresAt: new \DateTimeImmutable(NegotiationFixture::expires()),
            ),
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 1,
                unitPriceNet: 85.34,
                totalNet: 85.34,
                netRatio: 0.8,
            )]),
        );
    }

    private static function grossSnapshotAt(float $totalNet, float $totalGross): QuoteSnapshot
    {
        $snapshot = NegotiationFixture::grossSnapshot(state: 'in_review');

        return new QuoteSnapshot(
            identity: $snapshot->identity,
            revision: $snapshot->revision,
            totals: new QuoteTotals(totalNet: $totalNet, totalGross: $totalGross),
            lifecycle: $snapshot->lifecycle,
            content: $snapshot->content,
        );
    }
}
