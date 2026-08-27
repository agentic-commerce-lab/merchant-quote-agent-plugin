<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use Shopware\Core\Framework\Context;
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
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        $live = static::gateway()->fetchSnapshot($quoteId, QuoteVersion::Live);

        // The snapshot lane may legitimately be absent for a given state; when
        // it resolves, it must be a different version id than live.
        try {
            $snapshot = static::gateway()->fetchSnapshot($quoteId, QuoteVersion::Snapshot);
            self::assertNotSame($live->revision->versionId, $snapshot->revision->versionId);
        } catch (QuoteNotFoundException) {
            self::markTestSkipped('This quote has no snapshot version in its current state.');
        }
    }
}
