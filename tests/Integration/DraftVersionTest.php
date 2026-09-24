<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Draft Mode's premise, against the real shop: an offer written through a
 * draft gateway is priced by Shopware's own recalculation, leaves the live
 * quote untouched, and merge() carries it over. Run on BOTH lanes (7.13 and
 * 6.7.12) — the 2026-09-23 spike only proved 7.13.
 */
final class DraftVersionTest extends IntegrationTestCase
{
    public function testAQuoteWideDraftIsPricedInTheVersionAndMergedOnDemand(): void
    {
        $live = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $live->fetchSnapshot($quoteId);
        $versions = $this->versions();

        $versionId = $versions->create($quoteId);
        $draft = $versions->gateway($versionId);
        $draft->updateQuote($quoteId, new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0)));
        $draft->recalculate($quoteId);

        $drafted = $draft->fetchSnapshot($quoteId);
        $untouched = $live->fetchSnapshot($quoteId);

        self::assertSame($before->totals->totalNet, $untouched->totals->totalNet, 'The draft moved the live quote.');
        self::assertLessThan($before->totals->totalNet, $drafted->totals->totalNet, 'The draft priced nothing.');
        self::assertNotNull($drafted->totals->totalGross);

        $versions->merge($versionId);
        $merged = $live->fetchSnapshot($quoteId);

        self::assertEqualsWithDelta($drafted->totals->totalNet, $merged->totals->totalNet, 0.01);
        self::assertEqualsWithDelta((float) $drafted->totals->totalGross, (float) $merged->totals->totalGross, 0.01);
        self::assertCount(
            \count($before->content->comments),
            $merged->content->comments,
            'Merging duplicated or dropped the quote\'s comments.',
        );

        // The interface promises the version is gone after a merge.
        self::assertNothingLeftIn($versionId);
    }

    public function testAPerLineDraftStaysInTheVersion(): void
    {
        $live = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $live->fetchSnapshot($quoteId);
        $line = $before->content->lines[0] ?? null;
        self::assertNotNull($line, 'The fixture quote has no line.');

        $versions = $this->versions();
        $versionId = $versions->create($quoteId);
        $draft = $versions->gateway($versionId);
        $draft->updateLineItems($quoteId, [
            new QuoteLineItemChange(
                $line->identity->lineItemId,
                unitPriceNet: round($line->unitPriceNet * 0.9, precision: 2),
            ),
        ]);
        $draft->recalculate($quoteId);

        self::assertLessThan($before->totals->totalNet, $draft->fetchSnapshot($quoteId)->totals->totalNet);
        self::assertSame($before->totals->totalNet, $live->fetchSnapshot($quoteId)->totals->totalNet);
    }

    public function testDeletingDiscardsTheDraftAndASecondDeleteIsHarmless(): void
    {
        $live = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $live->fetchSnapshot($quoteId);
        $versions = $this->versions();

        $versionId = $versions->create($quoteId);
        self::assertTrue($versions->exists($versionId), 'A version create() just made reads as gone.');
        $versions->gateway($versionId)->updateQuote(
            $quoteId,
            new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0)),
        );
        $versions->delete($quoteId, $versionId);
        $versions->delete($quoteId, $versionId);

        self::assertFalse($versions->exists($versionId), 'A deleted version still reads as existing.');
        self::assertSame($before->totals->totalNet, $live->fetchSnapshot($quoteId)->totals->totalNet);

        // Unchanged live totals alone would pass against a delete that did nothing.
        // Read off the tables: a versioned DAL read falls back to the live row, so no read can prove the draft is gone.
        self::assertNothingLeftIn($versionId);
    }

    /**
     * Straight off the tables, because a DAL read cannot tell: in a version
     * context it resolves `version_id = COALESCE(<the version's row>, live)`
     * (EntityDefinitionQueryHelper::joinVersion()), so a version with no rows
     * left reads as the live quote rather than as nothing.
     */
    private static function assertNothingLeftIn(string $versionId): void
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $version = ['version' => Uuid::fromHexToBytes($versionId)];

        self::assertSame(
            ['quote' => 0, 'quote_line_item' => 0, 'version' => 0],
            [
                'quote' => (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM quote WHERE version_id = :version',
                    $version,
                ),
                'quote_line_item' => (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM quote_line_item WHERE version_id = :version',
                    $version,
                ),
                'version' => (int) $connection->fetchOne('SELECT COUNT(*) FROM version WHERE id = :version', $version),
            ],
            'The draft version left rows behind.',
        );
    }

    /** Built by hand, like ShopServices builds the gateway: plugin services are private in the test container. */
    private function versions(): QuoteDraftVersions
    {
        return new QuoteDraftVersions(
            static::getContainer()->get('quote.repository'),
            static::getContainer()->get('version.repository'),
            static::gatewayFactory(),
        );
    }
}
