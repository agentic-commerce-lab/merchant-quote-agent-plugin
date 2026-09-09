<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Proves the branch's central finding on a real, released SwagCommercial
 * 6.7.12 shop, in the one tax mode `UpdateLineItemsTest` and `UpdateQuoteTest`
 * could not reach: every quote that shop had was net, so
 * `QuoteFixture::anyQuoteId()` never picked a gross one and both levers'
 * gross-specific assertions were untested.
 *
 * A gross-capable customer (customer group `display_gross = TRUE`,
 * `QUOTE_MANAGEMENT` enabled) was created for this. It has no quotes yet, so
 * this test creates one through the plugin's own buyer gateway rather than
 * relying on `QuoteFixture` to find one.
 */
final class LegacyGrossQuoteTest extends IntegrationTestCase
{
    /** The gross-capable customer set up on the live shop for this test. */
    private const GROSS_CUSTOMER_ID = '01a084c27b2f7e9d9050df8f58809c64';

    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testBothMerchantLeversWorkOnAGrossQuote(): void
    {
        $context = BuyerQuoteContextFixture::contextForCustomer(static::getContainer(), self::GROSS_CUSTOMER_ID);
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        $created = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 2]],
            'Gross-mode lever proof.',
        );

        if ($created->taxStatus !== CartPrice::TAX_STATE_GROSS) {
            self::markTestSkipped(sprintf(
                'Quote taxStatus is "%s", not "gross" — the customer group behind customer %s '
                . '(display_gross) is not producing a gross quote. Check that group\'s display_gross flag.',
                $created->taxStatus ?? 'null',
                self::GROSS_CUSTOMER_ID,
            ));
        }

        $this->assertRepricedLineSurvivesAsGrossedUpNet($created->id);
        $this->assertAbsoluteDiscountIsConsumedAsGross($created->id);
    }

    /**
     * Lever 1, per-line price: reprice a line net, recalculate(), and assert
     * the stored `price.unitPrice` (a gross field) is the written net
     * grossed up by the line's own tax rate — AND that it is not the
     * original catalog price. That second assertion is the survival
     * property: it is what actually proves a per-line priceDefinition
     * survives `recalculate()` under
     * `SalesChannelContextRestorer::ADMIN_EDIT_QUOTE_PERMISSIONS`'
     * unconditional `SKIP_PRODUCT_RECALCULATION`.
     */
    private function assertRepricedLineSurvivesAsGrossedUpNet(string $quoteId): void
    {
        $context = Context::createDefaultContext();
        $gateway = static::gateway();

        $line = $this->firstProductLine($gateway->fetchSnapshot($quoteId)->content->lines);
        $originalStored = $this->storedPrice($line->identity->lineItemId, $context);
        $originalUnitPrice = $originalStored->getUnitPrice();

        $rules = $originalStored->getTaxRules();
        self::assertCount(1, $rules, 'This assertion assumes a single tax rule on the quoted line.');
        $rate = $rules->first()?->getTaxRate() ?? 0.0;
        self::assertGreaterThan(0.0, $rate, 'The quoted line is tax-free, so a gross-up cannot be shown.');

        $target = round($line->unitPriceNet * 0.9, precision: 2);
        $gateway->updateLineItems($quoteId, [
            new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, unitPriceNet: $target),
        ]);
        $gateway->recalculate($quoteId);

        $storedAfter = $this->storedPrice($line->identity->lineItemId, $context)->getUnitPrice();

        self::assertEqualsWithDelta(
            round($target * (1 + ($rate / 100)), precision: 2),
            $storedAfter,
            0.01,
            'Stored price.unitPrice is not the written net grossed up — the write path stored gross, '
            . 'or recalculate() discarded the write.',
        );
        self::assertNotEqualsWithDelta(
            $originalUnitPrice,
            $storedAfter,
            0.01,
            'Stored price.unitPrice still equals the original catalog price after recalculate() — the '
            . 'per-line priceDefinition did NOT survive, so SKIP_PRODUCT_RECALCULATION is not doing '
            . 'what the spec claims on this shop.',
        );
    }

    /**
     * Lever 2, quote-level discount: pins the tax-state trap documented on
     * `Bridge\Data\Discount` — an absolute value is consumed as a GROSS
     * amount — on an actual gross quote, rather than inferring it from a net
     * one via a tax factor.
     */
    private function assertAbsoluteDiscountIsConsumedAsGross(string $quoteId): void
    {
        $gateway = static::gateway();
        $subtotalNet = $this->quoteFloat($quoteId, 'subtotalNet');
        $taxFactor = $this->quoteFloat($quoteId, 'amountTotal') / $this->quoteFloat($quoteId, 'amountNet');
        self::assertGreaterThan(1.0, $taxFactor, 'The quote is tax-free, so gross and net cannot be told apart.');

        $gateway->updateQuote($quoteId, new QuoteUpdate(discount: new Discount(DiscountType::Absolute, 10.0)));
        $gateway->recalculate($quoteId);

        $totalNetAfter = $gateway->fetchSnapshot($quoteId)->totals->totalNet;

        self::assertEqualsWithDelta(
            round($subtotalNet - (10.0 / $taxFactor), precision: 2),
            $totalNetAfter,
            0.02,
            'An absolute discount of 10.00 did not behave as a GROSS amount on this gross quote — see '
            . 'Bridge\Data\Discount.',
        );
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

    /** A raw float straight off the quote entity, for fields the read model does not carry. */
    private function quoteFloat(string $quoteId, string $field): float
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = static::getContainer()->get('quote.repository');
        $quote = $repository
            ->search(new Criteria([$quoteId]), Context::createDefaultContext())
            ->getEntities()
            ->first();
        self::assertNotNull($quote, 'The quote under test disappeared.');

        return (float) $quote->get($field);
    }

    /**
     * Picks the first PRODUCT line, never a Shopware-generated
     * quote-discount line: discount lines have a null productId and a
     * negative price.
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

        self::fail('The quote under test has no product line item.');
    }
}
