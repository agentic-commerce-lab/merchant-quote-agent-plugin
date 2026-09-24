<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;

/**
 * A floored write must be idempotent (spec 2026-09-24): the buyer repeating
 * the ask, or Messenger retrying the pass, reproduces the same quote rather
 * than compounding a second discount onto the first.
 */
final class OfferApplierMarginFloorRepeatTest extends TestCase
{
    public function testARepeatedQuoteWideAskMovesNothing(): void
    {
        // Baseline 120/120 at quantity 1, floor 110 on line-1 (purchase 100,
        // margin 10%), none on line-2. Round one of "15% off" writes 110/102.
        $original = self::twoLines(120.0, 120.0, []);
        $roundOne = self::twoLines(110.0, 102.0, []);
        $first = new FakeQuoteGateway([$original, $roundOne]);
        $applier = OfferApplierMarginFloorTest::applier(new FakePurchasePrices(['prod-1' => 100.0]));

        $applier->apply(
            $first,
            $original,
            OfferApplierMarginFloorTest::settings(10.0),
            OfferApplierMarginFloorTest::quoteWide(15.0),
        );

        $written = [];
        foreach ($first->lineItemChanges as $change) {
            $written[$change->lineItemId] = $change->unitPriceNet;
        }
        self::assertSame(['line-1' => 110.0, 'line-2' => 102.0], $written);

        // The buyer insists (or Messenger retries the pass): same offer, on
        // round one's prices with round one's stored baseline.
        $stored = self::twoLines(110.0, 102.0, [
            QuoteBaseline::KEY => [
                'totalNet' => 240.0,
                'lines' => [
                    ['lineItemId' => 'line-1', 'unitPriceNet' => 120.0, 'quantity' => 1],
                    ['lineItemId' => 'line-2', 'unitPriceNet' => 120.0, 'quantity' => 1],
                ],
            ],
        ]);
        $second = new FakeQuoteGateway([$stored]);

        $applied = $applier->apply(
            $second,
            $stored,
            OfferApplierMarginFloorTest::settings(10.0),
            OfferApplierMarginFloorTest::quoteWide(15.0),
        );

        self::assertNotContains('updateLineItems', $second->calls, 'A repeated ask must not compound.');
        self::assertTrue($applied->verified);
    }

    /** @param array<string, mixed> $customFields */
    private static function twoLines(float $first, float $second, array $customFields): QuoteSnapshot
    {
        $base = NegotiationFixture::snapshot(totalNet: $first + $second);

        return NegotiationFixture::withCustomFields(new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: new QuoteTotals($first + $second, null, $first + $second),
            lifecycle: $base->lifecycle,
            content: new QuoteContent(lines: [
                new QuoteLineSnapshot(new QuoteLineIdentity('line-1', 'Widget', 'prod-1'), 1, $first, $first),
                new QuoteLineSnapshot(new QuoteLineIdentity('line-2', 'Gadget', 'prod-2'), 1, $second, $second),
            ], comments: []),
        ), $customFields);
    }
}
