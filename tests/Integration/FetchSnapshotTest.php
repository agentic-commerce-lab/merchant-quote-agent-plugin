<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Field names verified against a live shop during Task 4 (amountNet,
 * quoteNumber, unitPrice, requestedPrice, ...): all matched
 * SwagCommercial's QuoteDefinition as written, no corrections needed.
 */
final class FetchSnapshotTest extends IntegrationTestCase
{
    public function testReadsARealQuote(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $snapshot = static::gateway()->fetchSnapshot($quoteId);

        self::assertSame($quoteId, $snapshot->identity->quoteId);
        self::assertNotSame('', $snapshot->identity->quoteNumber);
        self::assertSame(3, \strlen($snapshot->identity->currencyIso), 'currencyIso should be an ISO 4217 code');
        self::assertNotSame('', $snapshot->lifecycle->stateTechnicalName);
        self::assertNotSame('', $snapshot->revision->versionId);
    }

    public function testUnknownQuoteThrows(): void
    {
        $this->expectException(QuoteNotFoundException::class);

        static::gateway()->fetchSnapshot(Uuid::randomHex());
    }

    public function testSnapshotLaneIsReadableAndDistinctFromLive(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::quoteIdWithSnapshotLane(static::getContainer(), $context);

        $live = static::gateway()->fetchSnapshot($quoteId, QuoteVersion::Live);
        $snapshot = static::gateway()->fetchSnapshot($quoteId, QuoteVersion::Snapshot);

        self::assertNotSame($live->revision->versionId, $snapshot->revision->versionId);
    }

    /**
     * The read model is net throughout, so the lines and the quote total must
     * be in the same tax space. `amountNet` is the independent yardstick: a
     * WriteProtected field Shopware fills from the calculated cart, not
     * something this bridge derives. Reconciling the summed line `totalNet`
     * against it is what pins the lines' tax state down.
     *
     * Before the tax-mode fix this failed by the VAT rate, because the lines
     * carried Shopware's gross `unitPrice`/`totalPrice` in fields named net.
     * Verified against all 36 live quotes in SQL: summed
     * `totalPrice - sum(calculatedTaxes[].tax)` equals `amount_net` exactly,
     * discounted quotes included.
     */
    public function testSummedLineNetsReconcileWithTheQuoteTotal(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $snapshot = static::gateway()->fetchSnapshot($quoteId);
        self::assertNotSame([], $snapshot->content->lines, 'The fixture quote needs at least one line item.');

        $summed = 0.0;
        foreach ($snapshot->content->lines as $line) {
            $summed += $line->totalNet;
        }

        self::assertEqualsWithDelta(
            $snapshot->totals->totalNet,
            $summed,
            0.01,
            'Summed line totalNet disagrees with the quote amountNet — the line prices are not net.',
        );
    }

    /**
     * The buyer-facing total, against real data.
     *
     * Quote 1020 on the test shop was told "your new total is 6913.11 EUR"
     * when the buyer owed 8226.60 — the reply had reached for `amountNet`.
     * Nothing caught it because the quote next to it was 0%-tax, where the two
     * figures are identical. This pins that the gross total is read at all and
     * is never below the net one.
     */
    public function testTheGrossTotalIsReadAndIsNeverBelowTheNetOne(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $totals = static::gateway()->fetchSnapshot($quoteId)->totals;

        self::assertNotNull($totals->totalGross, 'amountTotal must reach the snapshot.');
        self::assertGreaterThanOrEqual(
            $totals->totalNet - 0.01,
            $totals->totalGross,
            'A gross total below the net one means the two fields are swapped.',
        );
        self::assertSame($totals->totalGross, $totals->buyerFacingTotal());
    }

    public function testQuoteLevelDiscountRoundTripsWhenPresent(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        /** @var EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $repository */
        $repository = static::getContainer()->get('quote.repository');
        $raw = $repository
            ->search(new Criteria([$quoteId]), $context)
            ->getEntities()
            ->first();
        self::assertNotNull($raw);
        /** @var array{type?: mixed, value?: mixed}|null $rawDiscount */
        $rawDiscount = $raw->get('discount');

        $snapshot = static::gateway()->fetchSnapshot($quoteId);

        if (
            !\is_array($rawDiscount)
            || !\array_key_exists('type', $rawDiscount)
            || !\array_key_exists('value', $rawDiscount)
            || $rawDiscount['type'] === null
            || $rawDiscount['value'] === null
        ) {
            self::assertNull(
                $snapshot->totals->discount,
                'The fixture quote has no discount; asserting the null case.',
            );

            return;
        }

        self::assertNotNull($snapshot->totals->discount);
        self::assertSame($rawDiscount['type'], $snapshot->totals->discount->type->value);
        self::assertSame((float) $rawDiscount['value'], $snapshot->totals->discount->value);
    }

    /**
     * #18 selects a per-sales-channel policy from the snapshot rather than from
     * the servicing message, so the snapshot has to actually carry it.
     */
    public function testTheSnapshotCarriesTheSalesChannelId(): void
    {
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $snapshot = static::gateway()->fetchSnapshot($quoteId);

        self::assertNotSame('', $snapshot->identity->salesChannelId, 'The quote has no sales channel.');
        self::assertTrue(Uuid::isValid($snapshot->identity->salesChannelId));
    }
}
