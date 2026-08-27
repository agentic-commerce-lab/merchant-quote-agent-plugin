<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Tax-mode finding (spike deliverable, Task 5 Step 6, revised in fix round 1):
 * a reprice write round-trips through recalculate() unchanged, with no
 * divide/multiply by ~1.19, so the two-pass measure-and-rewrite correction the
 * old TypeScript implementation needed is not required here. But a stable
 * round trip is not the same as a net one: `price.unitPrice` is a GROSS field,
 * and the round trip was stable only because both ends were gross —
 * `isCalculated => true` made GrossPriceCalculator::getUnitPrice()
 * short-circuit and store the written number verbatim.
 *
 * The writer now sets `isCalculated => false`, so the gross calculator
 * converts net→gross on the way in, and the reader subtracts each line's
 * `calculatedTaxes` on the way out. `unitPriceNet` is therefore genuinely net
 * at both ends; `testARepricedLineIsStoredGrossedUp` is what proves the write
 * side, and `FetchSnapshotTest::testSummedLineNetsReconcileWithTheQuoteTotal`
 * the read side.
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

    /**
     * Write-side proof that `unitPriceNet` goes in as a net price. With
     * `isCalculated => false` the gross calculator runs `calculateGross()` on
     * it, so what Shopware stores in `price.unitPrice` — a gross field — has
     * to be the written net grossed up by the line's own tax rate. If the
     * writer ever went back to storing gross, the stored value would equal the
     * written number instead, and this fails by the VAT rate.
     */
    public function testARepricedLineIsStoredGrossedUp(): void
    {
        $context = Context::createDefaultContext();
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $line = $this->firstProductLine($gateway->fetchSnapshot($quoteId)->content->lines);
        $rules = $this->storedPrice($line->identity->lineItemId, $context)->getTaxRules();
        self::assertCount(1, $rules, 'This assertion assumes a single tax rule on the fixture line.');
        $rate = $rules->first()?->getTaxRate() ?? 0.0;
        self::assertGreaterThan(0.0, $rate, 'The fixture line is tax-free, so it cannot show a gross-up.');

        $target = round($line->unitPriceNet * 0.9, precision: 2);
        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, unitPriceNet: $target),
        ]);
        $gateway->recalculate($quoteId);

        self::assertEqualsWithDelta(
            round($target * (1 + ($rate / 100)), precision: 2),
            $this->storedPrice($line->identity->lineItemId, $context)->getUnitPrice(),
            0.01,
            'Stored price.unitPrice is not the written net grossed up — the write path stored gross.',
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

    /** Raw stored `price` of a line item, in Shopware's own gross space. */
    private function storedPrice(string $lineItemId, Context $context): CalculatedPrice
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = static::getContainer()->get('quote_line_item.repository');
        $lineItem = $repository
            ->search(new Criteria([$lineItemId]), $context)
            ->getEntities()
            ->first();
        self::assertNotNull($lineItem, 'The line item under test disappeared.');

        $price = $lineItem->get('price');
        self::assertInstanceOf(CalculatedPrice::class, $price);

        return $price;
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
