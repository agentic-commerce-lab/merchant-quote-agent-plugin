<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;

/**
 * Eval margin-floor-holds: round one's 8% already
 * sits between the floor and the next cent, so re-pricing the line at the
 * floor raised the quote by a cent instead of holding it.
 */
final class OfferApplierMarginFloorHoldTest extends TestCase
{
    public function testADeeperAskAtTheFloorNeverRaisesTheStandingTotal(): void
    {
        // 10 x 727.23 (7272.27), 8% off standing = 6690.49. Purchase 581.78 at
        // 15% margin -> floor 669.05 a unit, 6690.50 for the line.
        $base = NegotiationFixture::snapshot(totalNet: 7272.27);
        $live = NegotiationFixture::withCustomFields(
            new QuoteSnapshot(
                identity: $base->identity,
                revision: $base->revision,
                totals: new QuoteTotals(6690.49, new Discount(DiscountType::Percentage, 8.0), 6690.49),
                lifecycle: $base->lifecycle,
                content: new QuoteContent(lines: [
                    new QuoteLineSnapshot(new QuoteLineIdentity('line-1', 'Widget', 'prod-1'), 10, 727.23, 7272.27),
                    new QuoteLineSnapshot(new QuoteLineIdentity('discount', 'Discount', null), 1, -581.78, -581.78),
                ], comments: []),
            ),
            [
                QuoteBaseline::KEY => [
                    'totalNet' => 7272.27,
                    'lines' => [['lineItemId' => 'line-1', 'unitPriceNet' => 727.23, 'quantity' => 10]],
                ],
            ],
        );
        $gateway = new FakeQuoteGateway([$live]);

        OfferApplierMarginFloorTest::applier(new FakePurchasePrices(['prod-1' => 581.78]))->apply(
            $gateway,
            $live,
            OfferApplierMarginFloorTest::settings(15.0),
            OfferApplierMarginFloorTest::quoteWide(11.5),
        );

        self::assertNotContains(
            'updateLineItems',
            $gateway->calls,
            'The floor holds the standing 8%; it never re-prices above it.',
        );
        self::assertNull($gateway->quoteUpdates[0]->discount, 'The standing 8% stays; it is not reset to 0%.');
    }
}
