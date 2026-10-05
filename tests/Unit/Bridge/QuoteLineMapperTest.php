<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsk;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use MerchantQuoteAgentPlugin\Bridge\QuoteLineMapper;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\LegacyLineItemEntity;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\ModernLineItemEntity;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * The mirror is write-only: the quote SHOWS a chat ask the agent wrote onto a
 * line, and the agent's own read model does not. Every guard that treats
 * `requested_price` as buyer-only reads a line through this mapper, so one
 * subtraction here is what keeps all of them seeing what they saw before —
 * see MirroredAsks for which three and why.
 *
 * The 19% gross fixture is the shop's real shape: `requested_price` is stored
 * in the quote's own tax space, so 95.20 gross is the 80.00 net ask.
 *
 * Two trunk-only columns are exercised separately, through real `Entity`
 * subclasses rather than `ArrayEntity`: `deletedAt`, which decides whether a
 * line is skipped, and `requestedPrice`, read through QuoteLineNet. Neither
 * exists on a released SwagCommercial, and `Entity::get()` throws on both — an
 * `ArrayEntity` line item never throws on a missing key, so it could not tell
 * a passing guard from a deleted one.
 */
final class QuoteLineMapperTest extends TestCase
{
    public function testARequestedPriceTheAgentMirroredIsHiddenFromTheReadModel(): void
    {
        $lines = (new QuoteLineMapper(
            CommercialCapabilities::modern(),
        ))->map(self::quote(requestedPriceGross: 95.20, customFields: MirroredAsks::stamp([], [
            'line-1' => MirroredAsk::written(80.0, 100 / 119),
        ])));

        self::assertNull($lines[0]->requestedUnitPrice);
        self::assertSame(119.0, $lines[0]->totalInQuotePriceSpace);

        // QA-08, quote #1411: 318.79 was stored for a 289.81 net ask while the
        // line stood at 305.06 / 335.57, and the offer then repriced it to
        // 293.05 / 322.36. Through the NEW ratio the stored 318.79 reads as a
        // 289.80 net ask, which the guards took for the buyer's own and
        // negotiated again: a price written, the reply suppressed.
        $repriced = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quote(
            requestedPriceGross: 318.79,
            customFields: [MirroredAsks::KEY => ['line-1' => ['net' => 289.81, 'stored' => 318.79]]],
            totalGross: 322.36,
            tax: 29.31,
        ));

        self::assertSame(293.05, $repriced[0]->totalNet, 'The fixture must be the repriced line.');
        self::assertNull($repriced[0]->requestedUnitPrice);

        // A marker written before QA-08 carries the net ask alone and still
        // hides a line nobody repriced.
        $legacy = (new QuoteLineMapper(
            CommercialCapabilities::modern(),
        ))->map(self::quote(requestedPriceGross: 95.20, customFields: [MirroredAsks::KEY => ['line-1' => 80.0]]));

        self::assertNull($legacy[0]->requestedUnitPrice);
    }

    public function testARequestedPriceTheBUYERPlacedIsReadAsAlways(): void
    {
        $lines = (new QuoteLineMapper(
            CommercialCapabilities::modern(),
        ))->map(self::quote(requestedPriceGross: 95.20, customFields: []));

        self::assertSame(80.0, $lines[0]->requestedUnitPrice);
    }

    /** Both a large change and a one-cent edit must remain visible after a mirror. */
    public function testABuyerEditingOverAMirroredAskIsReadAgain(): void
    {
        $lines = (new QuoteLineMapper(
            CommercialCapabilities::modern(),
        ))->map(self::quote(requestedPriceGross: 90.00, customFields: MirroredAsks::stamp([], [
            'line-1' => MirroredAsk::written(80.0, 100 / 119),
        ])));

        self::assertSame(75.63, $lines[0]->requestedUnitPrice);
        $oneCentEdit = (new QuoteLineMapper(
            CommercialCapabilities::modern(),
        ))->map(self::quote(requestedPriceGross: 17.84, customFields: MirroredAsks::stamp([], [
            'line-1' => MirroredAsk::written(15.0, 100 / 119),
        ])));

        self::assertSame(14.99, $oneCentEdit[0]->requestedUnitPrice);
    }

    public function testAMirrorOnAnotherLineDoesNotHideThisLinesAsk(): void
    {
        $lines = (new QuoteLineMapper(
            CommercialCapabilities::modern(),
        ))->map(self::quote(requestedPriceGross: 95.20, customFields: MirroredAsks::stamp([], [
            'line-2' => MirroredAsk::written(80.0, 100 / 119),
        ])));

        self::assertSame(80.0, $lines[0]->requestedUnitPrice);
    }

    public function testAModernShopSkipsSoftDeletedLines(): void
    {
        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quoteWithLines([
            new ModernLineItemEntity(id: 'live', deletedAt: null, requestedPrice: null),
            new ModernLineItemEntity(id: 'gone', deletedAt: new \DateTimeImmutable(), requestedPrice: null),
        ]));

        self::assertCount(1, $lines);
        self::assertSame('live', $lines[0]->identity->lineItemId);
    }

    public function testALegacyShopKeepsEveryLineAndNeverReadsDeletedAt(): void
    {
        // LegacyLineItemEntity declares neither `deletedAt` nor
        // `requestedPrice`: on a released shop those columns do not exist and
        // reading either throws.
        $lines = (new QuoteLineMapper(CommercialCapabilities::legacy()))->map(self::quoteWithLines([
            new LegacyLineItemEntity(id: 'one'),
            new LegacyLineItemEntity(id: 'two'),
        ]));

        self::assertCount(2, $lines);
        self::assertNull($lines[0]->requestedUnitPrice);
    }

    public function testALinesUpdatedAtReachesTheReadModel(): void
    {
        $line = new ModernLineItemEntity(id: 'line-1');
        $line->setUpdatedAt(new \DateTimeImmutable('2026-09-16 11:00:00'));

        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quoteWithLines([$line]));

        self::assertSame(
            '2026-09-16 11:00:00',
            $lines[0]->updatedAt?->format('Y-m-d H:i:s'),
            'A per-line ask arrives with no comment, so the line timestamp is the only date it has.',
        );
    }

    /** A line written once and never edited has no `updatedAt` at all; the DAL leaves it null. */
    public function testALineNeverEditedFallsBackToItsCreatedAt(): void
    {
        $line = new ModernLineItemEntity(id: 'line-1');
        $line->setCreatedAt(new \DateTimeImmutable('2026-09-16 09:00:00'));

        $lines = (new QuoteLineMapper(CommercialCapabilities::modern()))->map(self::quoteWithLines([$line]));

        self::assertSame('2026-09-16 09:00:00', $lines[0]->updatedAt?->format('Y-m-d H:i:s'));
    }

    /**
     * One gross line; 119.00 at 19% unless a test reprices it.
     *
     * @param array<string, mixed> $customFields
     */
    private static function quote(
        float $requestedPriceGross,
        array $customFields,
        float $totalGross = 119.0,
        float $tax = 19.0,
    ): ArrayEntity {
        $taxes = new CalculatedTaxCollection([new CalculatedTax($tax, 19.0, $totalGross)]);

        return new ArrayEntity([
            'taxStatus' => 'gross',
            'customFields' => $customFields,
            'lineItems' => [
                new ArrayEntity([
                    'id' => 'line-1',
                    'label' => 'Widget',
                    'referencedId' => 'product-1',
                    'quantity' => 1,
                    'totalPrice' => $totalGross,
                    'requestedPrice' => $requestedPriceGross,
                    'price' => new CalculatedPrice($totalGross, $totalGross, $taxes, new TaxRuleCollection()),
                ]),
            ],
        ]);
    }

    /** @param list<Entity> $lines */
    private static function quoteWithLines(array $lines): Entity
    {
        return new ArrayEntity(['taxStatus' => CartPrice::TAX_STATE_NET, 'lineItems' => $lines]);
    }
}
