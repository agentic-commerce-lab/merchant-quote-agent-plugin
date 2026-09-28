<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Negotiation\OfferWrite;
use MerchantQuoteAgentPlugin\Negotiation\PredictedWrite;
use MerchantQuoteAgentPlugin\Negotiation\QuoteTotalRounding;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
use MerchantQuoteAgentPlugin\Policy\GoodsFactor;
use MerchantQuoteAgentPlugin\Policy\QuoteWidePercent;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Proves the branch's central finding on a real, released SwagCommercial
 * 6.7.12 shop, in the one tax mode `UpdateLineItemsTest` and `UpdateQuoteTest`
 * could not reach: every quote that shop had was net, so
 * `QuoteFixture::anyQuoteId()` never picked a gross one and both levers'
 * gross-specific assertions were untested.
 *
 * Builds a transaction-scoped gross customer group for a quote-capable
 * customer, then creates a quote through the real buyer gateway. No remote
 * shop's hardcoded customer ID or persistent tax-mode setup is required.
 */
final class LegacyGrossQuoteTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!CommercialAvailability::isLicensed()) {
            self::markTestSkipped('SwagCommercial quote management is not licensed in this shop.');
        }
    }

    public function testBothMerchantLeversWorkOnAGrossQuote(): void
    {
        $context = self::grossContext();
        $productId = BuyerQuoteFixture::anyPurchasableProductId(static::getContainer());

        $created = $this->buyerGateway()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 2]],
            'Gross-mode lever proof.',
        );

        self::assertSame(CartPrice::TAX_STATE_GROSS, $created->taxStatus);

        $this->assertRepricedLineSurvivesAsGrossedUpNet($created->id);
        $this->assertAbsoluteDiscountIsConsumedAsGross($created->id);
    }

    /**
     * Rounding control, quote_total mode (spec 2026-09-28), through
     * SwagCommercial's own recalculation, on the hardest shape a gross quote
     * takes: two tax rates (19% and 7%) and a shipping charge. The absolute
     * discount QuoteTotalRounding computes lands the buyer-facing total
     * exactly on the step, SwagCommercial takes it off the goods alone (the
     * shipping charge does not move), PredictedWrite's net prediction of it
     * is what the shop books, so the pre-write checks measure the real
     * result, and the next round reads it off the discount line as the same
     * goods factor.
     *
     * Deliberately no post-write `verified` assertion: on a quote with
     * shipping, the line check's NetFactor counts the negative discount line
     * in its denominator and reports a false "above its reference price"
     * (pre-existing, fixed by PR #212). Assert it once that lands.
     */
    public function testARoundedAbsoluteDiscountLandsOnTheRoundTotal(): void
    {
        $lines = array_map(static fn(string $productId): array => [
            'product_id' => $productId,
            'quantity' => 20,
        ], self::twoRatesWithShipping());
        $created = $this->buyerGateway()->requestQuote(self::grossContext(), $lines, 'Rounding landing proof.');
        self::assertSame(CartPrice::TAX_STATE_GROSS, $created->taxStatus);

        $price = $this->quote($created->id)->get('price');
        self::assertInstanceOf(CartPrice::class, $price);
        $rates = array_map(
            static fn(CalculatedTax $tax): float => $tax->getTaxRate(),
            array_values($price->getCalculatedTaxes()->getElements()),
        );
        sort($rates);
        self::assertSame([7.0, 19.0], $rates, 'The quote under test does not carry both tax rates.');
        $shipping = $this->quote($created->id)->get('shippingCosts');
        self::assertInstanceOf(CalculatedPrice::class, $shipping);
        self::assertGreaterThan(0.0, $shipping->getTotalPrice(), 'The quote under test carries no shipping.');

        $gateway = static::gateway();
        $reference = $gateway->fetchSnapshot($created->id);
        $live = SnapshotAdapter::toPolicy($reference);
        $offer = new ProposedOffer($live->totalNet, new OfferedPrice(discountPercent: 7.34));
        [$write, $rounding] = QuoteTotalRounding::of(
            OfferWrite::of($offer, null, $reference, QuoteWidePercent::of($offer, $live->lines, $live->lines)),
            $reference,
            $live,
            new QuoteLimits(
                maxDiscountPercent: 10.0,
                validityDays: 14,
                roundingMode: RoundingMode::QuoteTotal,
                roundingStep: 1.0,
            ),
            false,
        );

        self::assertNotNull($rounding);
        self::assertNull($rounding->skipped, 'A fresh gross quote must round, not skip: ' . $rounding->skipped?->value);
        self::assertSame(DiscountType::Absolute, $write->discount?->type);

        $gateway->updateQuote($created->id, new QuoteUpdate(discount: $write->discount));
        $gateway->recalculate($created->id);
        $after = $gateway->fetchSnapshot($created->id);

        self::assertEqualsWithDelta(
            $rounding->rounded,
            $after->totals->buyerFacingTotal(),
            0.001,
            'The absolute discount did not land the buyer-facing total on the round figure.',
        );
        $shippingAfter = $this->quote($created->id)->get('shippingCosts');
        self::assertInstanceOf(CalculatedPrice::class, $shippingAfter);
        self::assertEqualsWithDelta(
            $shipping->getTotalPrice(),
            $shippingAfter->getTotalPrice(),
            0.001,
            'The absolute discount was spread over the shipping charge, not over the goods alone.',
        );
        self::assertEqualsWithDelta(
            PredictedWrite::of($write, $live)->snapshot->totalNet,
            $after->totals->totalNet,
            0.02,
            'PredictedWrite mispredicts the net relief of an absolute discount.',
        );
        self::assertEqualsWithDelta(
            $write->discountFactor,
            GoodsFactor::of(SnapshotAdapter::toPolicy($after)->lines),
            0.001,
            'The next round reads the absolute discount off its line as a different goods factor.',
        );
    }

    /**
     * Two products at 19% and 7%, and a shipping charge, inside this test's
     * rolled-back transaction: the seeded shop has every product at the
     * standard rate and ships for 0.00 (scripts/shop-check-shipping.sh).
     *
     * @return list<string> the two product ids
     */
    private static function twoRatesWithShipping(): array
    {
        $container = static::getContainer();
        $context = Context::createDefaultContext();
        $productIds = self::connection($container)
            ->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM product'
            . ' WHERE active = 1 AND version_id = :version AND parent_id IS NULL'
            . ' AND child_count = 0 ORDER BY product_number LIMIT 2', [
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]);
        self::assertCount(2, $productIds, 'The shop has fewer than two simple active products to quote.');
        $productIds = array_map(strval(...), $productIds);

        $reducedRate = self::repository($container, 'tax.repository')
            ->searchIds((new Criteria())->addFilter(new EqualsFilter('taxRate', 7.0)), $context)
            ->firstId();
        self::assertNotNull($reducedRate, 'The shop has no 7% tax rate.');
        self::repository($container, 'product.repository')
            ->update([['id' => $productIds[1], 'taxId' => $reducedRate]], $context);

        $shippingPrices = self::repository($container, 'shipping_method_price.repository')
            ->searchIds(new Criteria(), $context)
            ->getIds();
        self::repository($container, 'shipping_method_price.repository')
            ->update(array_map(static fn(mixed $id): array => [
                'id' => $id,
                'currencyPrice' => [[
                    'currencyId' => Defaults::CURRENCY,
                    'net' => 5.0,
                    'gross' => 5.95,
                    'linked' => false,
                ]],
            ], array_values($shippingPrices)), $context);

        return $productIds;
    }

    private static function grossContext(): SalesChannelContext
    {
        $container = static::getContainer();
        $customerId = BuyerQuoteFixture::anyQuoteCapableCustomerId($container);
        $groupId = Uuid::randomHex();
        $writeContext = Context::createDefaultContext();
        self::repository($container, 'customer_group.repository')
            ->create([[
                'id' => $groupId,
                'name' => 'Gross quote integration fixture',
                'displayGross' => true,
            ]], $writeContext);
        self::repository($container, 'customer.repository')
            ->update([[
                'id' => $customerId,
                'groupId' => $groupId,
            ]], $writeContext);
        $context = BuyerQuoteContextFixture::contextForCustomer($container, $customerId);
        $context->addState(AgentContext::STATE, Context::SKIP_TRIGGER_FLOW);

        return $context;
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
        $subtotalNet = (float) $this->quote($quoteId)->get('subtotalNet');
        $taxFactor =
            (float) $this->quote($quoteId)->get('amountTotal') / (float) $this->quote($quoteId)->get('amountNet');
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

    /** The raw quote entity, for fields the read model does not carry. */
    private function quote(string $quoteId): Entity
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = static::getContainer()->get('quote.repository');
        $quote = $repository
            ->search(new Criteria([$quoteId]), Context::createDefaultContext())
            ->getEntities()
            ->first();
        self::assertNotNull($quote, 'The quote under test disappeared.');

        return $quote;
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
