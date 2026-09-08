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
}
