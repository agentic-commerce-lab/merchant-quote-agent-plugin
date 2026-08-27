<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use Shopware\Core\Framework\Context;

/**
 * Tax-mode finding (spike deliverable, Task 5 Step 6): after a reprice write
 * followed by recalculate(), the read-back unitPriceNet matched the written
 * net price directly, within the 0.01 assertion delta. No divide/multiply by
 * ~1.19 was observed, so the two-pass measure-and-rewrite correction the old
 * TypeScript implementation needed is NOT required for an in-process write
 * through this gateway: priceDefinition.price is interpreted as a net price
 * here, matching the space fetchSnapshot()->unitPriceNet already reads in,
 * despite these quotes' taxStatus being gross.
 */
final class UpdateLineItemsTest extends IntegrationTestCase
{
    /**
     * THE load-bearing test. A repriced line is only honoured if it also
     * carries customFields['quote_custom_offer_price'] === true; without it,
     * recalculate() re-prices from the catalog and silently discards the
     * priceDefinition we wrote.
     */
    public function testRepricedLineSurvivesRecalculate(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $before = $gateway->fetchSnapshot($quoteId);
        $line = $this->firstProductLine($before->content->lines);

        $target = round($line->unitPriceNet * 0.9, 2);
        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, unitPriceNet: $target),
        ]);

        $gateway->recalculate($quoteId);

        $after = $gateway->fetchSnapshot($quoteId);
        $sameLine = null;
        foreach ($after->content->lines as $candidate) {
            if ($candidate->identity->lineItemId === $line->identity->lineItemId) {
                $sameLine = $candidate;
            }
        }

        self::assertNotNull($sameLine, 'The repriced line disappeared after recalculate.');
        self::assertEqualsWithDelta(
            $target,
            $sameLine->unitPriceNet,
            0.01,
            'Price did not survive recalculate — the custom-price flag is missing or ineffective.',
        );
    }

    public function testQuantityChangeApplies(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $line = $this->firstProductLine($gateway->fetchSnapshot($quoteId)->content->lines);

        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, quantity: $line->quantity + 1),
        ]);

        $after = $gateway->fetchSnapshot($quoteId);
        foreach ($after->content->lines as $candidate) {
            if ($candidate->identity->lineItemId === $line->identity->lineItemId) {
                self::assertSame($line->quantity + 1, $candidate->quantity);

                return;
            }
        }

        self::fail('Line item vanished after a quantity change.');
    }

    public function testRemovalSoftDeletesTheLine(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $lines = $gateway->fetchSnapshot($quoteId)->content->lines;
        $victim = $this->firstProductLine($lines)->identity->lineItemId;

        $gateway->updateLineItems($quoteId, [new QuoteLineItemChange(lineItemId: $victim, remove: true)]);

        foreach ($gateway->fetchSnapshot($quoteId)->content->lines as $line) {
            self::assertNotSame($victim, $line->identity->lineItemId, 'Removed line still reads back.');
        }
    }

    /**
     * Picks the first PRODUCT line, never a Shopware-generated
     * quote-discount line: discount lines have a null productId and a
     * negative price, and recalculate() regenerates them from scratch, so
     * repricing one would fail for a reason unrelated to the custom-price
     * flag under test.
     *
     * @param list<QuoteLineSnapshot> $lines
     */
    private function firstProductLine(array $lines): QuoteLineSnapshot
    {
        foreach ($lines as $line) {
            if ($line->identity->productId !== null) {
                return $line;
            }
        }

        self::fail('The fixture quote has no product line item (only generated discount lines).');
    }
}
