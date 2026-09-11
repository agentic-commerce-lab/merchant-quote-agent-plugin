<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * A buyer's ask is measured against the ORIGINAL prices, the same anchor every
 * offer check uses — never against the last round's already-reduced ones.
 *
 * Live quote 1101: round one took 7.99 to 7.19 (10%). In round two the buyer
 * asked 7.10. Measured against 7.19 that is a 1.25% ask, and that is what the
 * model was told and what the checks were tightened to — but the checks bound
 * against the baseline 7.99, where 7.10 is 11.14% off. The model did exactly
 * as told, proposed 1.25%, and was refused for it: a grantable ask inside a
 * 15% cap escalated as proposal_rejected.
 */
final class BaselineAnchoredAskTest extends TestCase
{
    /**
     * Original 80.00 a unit, now 72.00 after an earlier round, buyer asks
     * 71.20: 11% off the original, 1.11% off the current price. Cap 15%.
     */
    private static function secondRound(): PipelineHarness
    {
        $harness = PipelineHarness::with([
            '{}',
            '{"action":"offer","message":"11% off.","terms":{"discountPercent":11}}',
            'Here you go.',
        ]);
        $baseline = NegotiationFixture::baselineOf(800.0, 80.0);
        $harness->before = NegotiationFixture::withCustomFields(
            NegotiationFixture::snapshot(
                comments: [NegotiationFixture::buyerComment('What do you think about this?', '2026-08-28 09:00:00')],
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

        return $harness;
    }

    public function testTheModelIsToldTheAskAgainstTheOriginalPrice(): void
    {
        $harness = self::secondRound();

        $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertStringContainsString(
            'maximum discount you may grant: 11.00%',
            $harness->spy->userPrompts[1],
            '71.20 against an original 80.00 is an 11% ask; 1.11% would be the ask measured against the reduced 72.00.',
        );
    }

    public function testAnAskInsideTheCapIsGrantedOnTheSecondRoundToo(): void
    {
        $harness = self::secondRound();

        $outcome = $harness->pipeline->service(
            $harness->before,
            $harness->gateway,
            NegotiationFixture::settings(maxDiscountPercent: 15.0),
            NegotiationFixture::context(),
        );

        self::assertSame(NegotiationOutcome::Offered, $outcome);
        self::assertSame(71.2, $harness->gateway->lineItemChanges[0]->unitPriceNet);
    }
}
