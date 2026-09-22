<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * The two numbers the buyer reads in an agent reply: the total, and how much
 * the quote came down by.
 *
 * Both were wrong on live quotes 1019 and 1020.
 *
 * The TOTAL was the net one. Quote 1020 is a 19%-tax quote whose buyer owes
 * 8226.60 EUR gross; the agent wrote "bringing your new total to 6913.11 EUR"
 * — understating the price by 1313.49. Quote 1019 hid it, being a 0%-tax
 * product where net and gross are the same number.
 *
 * The PERCENTAGE was measured against the previous round rather than the
 * original quote, so it compounded silently. 1019 went 2% then 3% and the
 * buyer was told both, having actually received 4.94% — then asked for "5% at
 * least" when 4.94% was already on the table.
 */
final class BuyerFacingNumbersTest extends TestCase
{
    private const OFFER_2_PERCENT = '{"action":"offer","message":"2% off.","terms":{"discountPercent":2}}';

    // PipelineHarness::withTotals() always scripts the buyer comment "what
    // can you do on price?" -- a best-price ask with no figure attached, not
    // #177's empty-extraction shape (no ask anywhere).
    private const ASK_BEST_PRICE = '{"price":{"bestPriceRequested":true}}';

    public function testTheBuyerIsToldTheGrossTotalTheyOweNotTheNetOne(): void
    {
        // Quote 1020's shape: a 19%-tax quote. The reply must name 8226.60,
        // never the 6913.11 net figure.
        $harness = PipelineHarness::withTotals(
            [self::ASK_BEST_PRICE, self::OFFER_2_PERCENT, 'Here is your offer.'],
            afterNet: 6913.11,
            afterGross: 8226.60,
            beforeNet: 7054.19,
        );

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        $comment = $harness->gateway->comments[0] ?? '';
        self::assertStringContainsString('8226.60', $comment, 'The buyer must be told the total they owe.');
        self::assertStringNotContainsString('6913.11', $comment, 'The net total must not be quoted at the buyer.');
    }

    public function testTheReductionIsMeasuredAgainstTheOriginalQuoteNotTheLastRound(): void
    {
        // Quote 1019's second round: baseline 6348.30, this round opens at
        // 6221.30 after an earlier 2%, and lands on 6034.70. Per-round that is
        // 3%; against the quote the buyer actually asked about it is 4.94%,
        // and 4.94% is the only figure that answers "how much off?".
        $harness = PipelineHarness::withTotals(
            [self::ASK_BEST_PRICE, self::OFFER_2_PERCENT, 'Here is your offer.'],
            afterNet: 6034.70,
            afterGross: 6034.70,
            beforeNet: 6221.30,
            baselineNet: 6348.30,
        );

        $outcome = $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertStringContainsString('4.94%', $harness->gateway->comments[0] ?? '');
    }

    public function testWithNoBaselineTheFirstRoundStillMeasuresAgainstItself(): void
    {
        // Round one has no stored baseline yet, so the opening total IS the
        // original and nothing changes.
        $harness = PipelineHarness::withTotals(
            [self::ASK_BEST_PRICE, self::OFFER_2_PERCENT, 'Here is your offer.'],
            afterNet: 980.0,
            afterGross: 980.0,
            beforeNet: 1000.0,
        );

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString('2%', $harness->gateway->comments[0] ?? '');
    }
}
