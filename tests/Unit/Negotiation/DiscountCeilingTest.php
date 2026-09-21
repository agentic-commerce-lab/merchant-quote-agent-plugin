<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * The agent may never grant more discount than the buyer asked for.
 *
 * Quotes 1017 and 1018 on the test shop both gave away margin nobody
 * requested: 1017 asked 2.70% and got 5%, 1018 asked 3.41% on 15 units and got
 * 5%. Both were inside maxDiscountPercent, so every offer check passed. The
 * model had been handed the published volume tier as guidance and anchored on
 * it — it said so itself: "In line with our volume tier for purchasing 10 or
 * more units per item, we are pleased to offer a 5.0% discount".
 *
 * The fix tightens maxDiscountPercent for the pass, so the cap the model is
 * TOLD is already the buyer's ask. Clamping afterwards would leave it
 * anchoring high and then being refused, which turns a grantable ask into an
 * escalation.
 */
final class DiscountCeilingTest extends TestCase
{
    private const OFFER_5_PERCENT = '{"action":"offer","message":"5% off.","terms":{"discountPercent":5}}';

    public function testTheModelIsToldTheBuyersAskNotTheConfiguredCap(): void
    {
        // Unit 100.00, requested 98.00 on 10 units: a 2% ask under a 15% cap.
        $harness = PipelineHarness::with(['{}', self::OFFER_5_PERCENT, 'Here you go.'], reReadTotalNet: 980.0);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('can you do these prices?', '2026-08-28 09:00:00'),
        ], requestedUnitPrice: 98.0);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 2.00%',
            $harness->spy->userPrompts[1],
            'The negotiate prompt must cap the model at the ask, not at the configured 15%.',
        );
    }

    public function testAQuoteWithNoStatedAskKeepsTheConfiguredCap(): void
    {
        $harness = PipelineHarness::with(['{}', self::OFFER_5_PERCENT, 'Here you go.'], reReadTotalNet: 950.0);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('what can you do for us?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 15.00%',
            $harness->spy->userPrompts[1],
            'With no ask to bound it, the merchant cap stands unchanged.',
        );
    }

    public function testAnAskAboveTheCapLeavesTheCapStanding(): void
    {
        // Requested 50.00 against 100.00 is a 50% ask. min() must leave the
        // 15% cap standing so the existing counter instruction still fires;
        // tightening UP to the ask would hand away 50%.
        $harness = PipelineHarness::with(['{}', self::OFFER_5_PERCENT, 'Here you go.'], reReadTotalNet: 950.0);
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('we need 50 each', '2026-08-28 09:00:00'),
        ], requestedUnitPrice: 50.0);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 15.00%',
            $harness->spy->userPrompts[1],
            'An ask above the cap must not raise the cap.',
        );
    }

    public function testABuyerAskingForTheBestPriceIsNotCapped(): void
    {
        // "your best price" is an explicit request for the maximum, so there
        // is no stated number to hold the agent to.
        $harness = PipelineHarness::with(
            ['{"price":{"bestPriceRequested":true}}', self::OFFER_5_PERCENT, 'Here you go.'],
            reReadTotalNet: 950.0,
        );
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('what is your best price?', '2026-08-28 09:00:00'),
        ], requestedUnitPrice: 98.0);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 15.00%',
            $harness->spy->userPrompts[1],
            'A best-price ask names no number, so the merchant cap stands.',
        );
    }

    public function testAPerUnitPriceTypedInACommentCapsTheModelToo(): void
    {
        // The same 2% ask as the first test, arriving through the only other
        // channel a buyer has: typed in the conversation instead of filled
        // into the storefront's "Requested price" field. No structured ask on
        // the line, so CommentLineTargets adopts the comment's target and
        // QuoteDiscountApplier prices against 98.00 — the cap the model is
        // told has to be measured on the same number.
        $harness = PipelineHarness::with(
            [
                '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":98.0}]}}',
                self::OFFER_5_PERCENT,
                'Here you go.',
            ],
            reReadTotalNet: 980.0,
        );
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('can you do 98 each?', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 2.00%',
            $harness->spy->userPrompts[1],
            'A per-unit price typed in a comment is the buyer\'s ask like any other, and must cap the model.',
        );
    }

    public function testAQuoteLevelBudgetCapsTheModelToo(): void
    {
        // "Max cost should be 2500" on a 1000 fixture quote: 900 against a
        // 1000 total is a 10% ask. Nothing lands on a line here — the buyer
        // named one number for the whole quote — so the cap can only come
        // from the budget itself.
        $harness = PipelineHarness::with(
            ['{"price":{"targetTotal":900.0}}', self::OFFER_5_PERCENT, 'Here you go.'],
            reReadTotalNet: 950.0,
        );
        $snapshot = NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('max cost should be 900 for everything', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 10.00%',
            $harness->spy->userPrompts[1],
            'A budget for the whole quote is an ask like any other, and must cap the model.',
        );
    }

    public function testAGrossQuoteLevelBudgetIsCappedInNetSpace(): void
    {
        // The same hazard as the per-unit case, one level up: 900 gross on a
        // quote whose 1000 gross is 800 net is a 720 net target — a 10% ask,
        // not the 12.5% the number as typed implies.
        $harness = PipelineHarness::with(
            ['{"price":{"targetTotal":900.0}}', self::OFFER_5_PERCENT, 'Here you go.'],
            reReadTotalNet: 760.0,
        );
        $snapshot = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('max cost should be 900 all in', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 10.00%',
            $harness->spy->userPrompts[1],
            'A gross budget must cap the model on its net value, not on the number as typed.',
        );
    }

    public function testAGrossPriceTypedInACommentIsCappedInNetSpace(): void
    {
        // The hazard that could have justified leaving comment targets out:
        // the buyer types a GROSS figure, and a cap measured on it unconverted
        // would be one tax factor wrong. It is already handled upstream —
        // AskInterpreter runs BuyerPriceSpace::toNet() before anything sees
        // the ask — so 90.00 gross on a 25% quote is 72.00 net against an
        // 80.00 net line: a 10% ask, not the 12.5% the raw figure implies.
        $harness = PipelineHarness::with(
            [
                '{"structural":{"lineChanges":[{"lineItemId":"line-1","targetUnitPrice":90.0}]}}',
                self::OFFER_5_PERCENT,
                'Here you go.',
            ],
            reReadTotalNet: 760.0,
        );
        $snapshot = NegotiationFixture::grossSnapshot([
            NegotiationFixture::buyerComment('90 per unit and we have a deal', '2026-08-28 09:00:00'),
        ]);

        $harness->pipeline->service(
            $snapshot,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 10.00%',
            $harness->spy->userPrompts[1],
            'A gross figure must cap the model on its net value, not on the number as typed.',
        );
    }
}
