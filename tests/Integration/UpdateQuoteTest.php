<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Quote-level writes against the live shop.
 *
 * The discount cases split along the tax-state line documented on
 * `Bridge\Data\Discount`: `Percentage` is tax-state invariant, so it is
 * asserted in net space against the quote's own pre-discount `subtotalNet`.
 * `Absolute` is not, and is asserted in the gross space Shopware actually
 * interprets it in — pinning the trap rather than a net figure that would only
 * hold at one VAT rate.
 */
final class UpdateQuoteTest extends IntegrationTestCase
{
    public function testExpirationAndDiscountRoundTrip(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $expires = new \DateTimeImmutable('+14 days');

        $gateway->updateQuote(
            $quoteId,
            new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0), expiresAt: $expires),
        );

        $after = $gateway->fetchSnapshot($quoteId);

        self::assertNotNull($after->lifecycle->expiresAt);
        // Compared as a Unix timestamp: the DAL normalizes to UTC on the way
        // in, so a wall-clock comparison would only pass under a UTC-set PHP.
        self::assertSame($expires->format('U'), $after->lifecycle->expiresAt->format('U'));
        self::assertNotNull($after->totals->discount, 'The written discount did not read back.');
        self::assertSame(DiscountType::Percentage, $after->totals->discount->type);
        self::assertSame(5.0, $after->totals->discount->value);
    }

    /**
     * The safe discount path, end to end through SwagCommercial's own
     * QuoteDiscountProcessor. `subtotalNet` is the PRE-discount net total and
     * recalculate() leaves it alone (live evidence: the shop's discounted
     * quotes all read subtotalNet 672.27 / totalNet 638.66), so this holds
     * whatever discount the fixture quote already carried.
     */
    public function testAPercentageDiscountTakesItsPercentOffTheNetTotal(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $subtotalNet = $this->quoteFloat($quoteId, 'subtotalNet');
        self::assertGreaterThan(0.0, $subtotalNet, 'The fixture quote is worth nothing, so 5% of it proves nothing.');

        $gateway->updateQuote($quoteId, new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0)));
        $gateway->recalculate($quoteId);

        self::assertEqualsWithDelta(
            round($subtotalNet * 0.95, precision: 2),
            $gateway->fetchSnapshot($quoteId)->totals->totalNet,
            0.01,
            'A 5% quote discount did not take 5% off the net total.',
        );
    }

    /**
     * Pins the tax-state trap documented on `Bridge\Data\Discount`: an
     * absolute value is consumed as a GROSS amount, because
     * AbsolutePriceCalculator builds a QuantityPriceDefinition with
     * isCalculated defaulting to true. The whole written value comes off the
     * gross total, so strictly less than it comes off the net total. If anyone
     * later "fixes" this by scaling the value on our side, the first assertion
     * breaks by the VAT rate.
     *
     * Requires a GROSS quote: `QuoteFixture::anyQuoteId()` picks whichever
     * editable quote sorts first, with no control over tax mode, and this
     * shop's quotes are not all gross. Skipped rather than weakened when the
     * picked quote is net — `LegacyGrossQuoteTest` proves the same
     * gross-consumption behaviour on a quote it creates specifically to be
     * gross.
     */
    public function testAnAbsoluteDiscountIsConsumedAsGrossNotNet(): void
    {
        $gateway = static::gateway();
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $taxStatus = $this->quoteTaxStatus($quoteId, $context);

        if ($taxStatus !== CartPrice::TAX_STATE_GROSS) {
            self::markTestSkipped(sprintf(
                'The fixture quote\'s taxStatus is "%s", not "gross" — this assertion only holds in gross '
                . 'mode. See LegacyGrossQuoteTest, which proves the same gross-consumption behaviour on a '
                . 'quote it creates specifically to be gross.',
                $taxStatus ?? 'null',
            ));
        }

        $subtotalNet = $this->quoteFloat($quoteId, 'subtotalNet');
        // Derived from the quote rather than hardcoded at 1.19, and invariant
        // under whatever discount the fixture already carried, since a
        // percentage discount scales gross and net alike.
        $taxFactor = $this->quoteFloat($quoteId, 'amountTotal') / $this->quoteFloat($quoteId, 'amountNet');
        self::assertGreaterThan(
            1.0,
            $taxFactor,
            'The fixture quote is tax-free, so gross and net cannot be told apart.',
        );

        $gateway->updateQuote($quoteId, new QuoteUpdate(discount: new Discount(DiscountType::Absolute, 100.0)));
        $gateway->recalculate($quoteId);

        // Gross reading: the whole 100.00 comes off the gross total, so only
        // 100/taxFactor of it comes off the net one. Under a net reading
        // Shopware would gross the value up first and the net relief would be
        // the full 100.00 — that is the failure to expect if this ever changes.
        self::assertEqualsWithDelta(
            round($subtotalNet - (100.0 / $taxFactor), precision: 2),
            $gateway->fetchSnapshot($quoteId)->totals->totalNet,
            0.02,
            'An absolute discount of 100.00 did not behave as a GROSS amount — see Bridge\Data\Discount.',
        );
    }

    /**
     * The A2CN act chain stores one act per top-level customFields key so that
     * buyer and seller appends never collide. A replacing write would destroy
     * the counterparty's acts, so merge behaviour is a correctness requirement.
     */
    public function testCustomFieldsMergeRatherThanReplace(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['a2cn_act_0001_b' => 'buyer']));
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['a2cn_act_0001_s' => 'seller']));

        $fields = $gateway->fetchSnapshot($quoteId)->lifecycle->customFields;

        self::assertSame('buyer', $fields['a2cn_act_0001_b'] ?? null, 'Second write clobbered the first key.');
        self::assertSame('seller', $fields['a2cn_act_0001_s'] ?? null);
    }

    /**
     * `[]` is the one customFields value the DAL does NOT merge:
     * CustomFieldsSerializer::encode() short-circuits it to a literal `'{}'`
     * before the JsonUpdateCommand path, replacing the column. QuoteWriter
     * therefore drops it, since merging an empty map means changing nothing.
     */
    public function testAnEmptyCustomFieldsMapDoesNotClearTheExistingOnes(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['a2cn_act_0001_b' => 'buyer']));
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: []));

        self::assertSame(
            'buyer',
            $gateway->fetchSnapshot($quoteId)->lifecycle->customFields['a2cn_act_0001_b'] ?? null,
            'An empty customFields map wiped the act chain.',
        );
    }

    /**
     * An empty update must not reach the DAL at all. Asserted on the revision
     * rather than on the totals: a write that changed nothing semantically
     * would still bump `updatedAt`, and callers use the revision for
     * optimistic locking, so a spurious bump would make their next write fail.
     */
    public function testEmptyUpdateIsANoOp(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $before = $gateway->fetchSnapshot($quoteId)->revision;
        $gateway->updateQuote($quoteId, new QuoteUpdate());

        self::assertTrue(
            $before->matches($gateway->fetchSnapshot($quoteId)->revision),
            'An empty update moved the quote revision, so it was written after all.',
        );
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

    /** Raw `taxStatus` off the quote entity itself — the read model does not carry it. */
    private function quoteTaxStatus(string $quoteId, Context $context): ?string
    {
        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = static::getContainer()->get('quote.repository');
        $quote = $repository
            ->search(new Criteria([$quoteId]), $context)
            ->getEntities()
            ->first();
        self::assertNotNull($quote, 'The quote under test disappeared.');

        $taxStatus = $quote->get('taxStatus');

        return \is_string($taxStatus) ? $taxStatus : null;
    }
}
