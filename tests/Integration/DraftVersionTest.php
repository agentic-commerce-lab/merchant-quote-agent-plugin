<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use Shopware\Core\Framework\Context;

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
        $versions->gateway($versionId)->updateQuote(
            $quoteId,
            new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0)),
        );
        $versions->delete($quoteId, $versionId);
        $versions->delete($quoteId, $versionId);

        self::assertSame($before->totals->totalNet, $live->fetchSnapshot($quoteId)->totals->totalNet);
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
