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
}
