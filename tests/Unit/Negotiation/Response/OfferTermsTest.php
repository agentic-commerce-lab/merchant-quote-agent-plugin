<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiationAction;
use MerchantQuoteAgentPlugin\Negotiation\Response\OfferTerms;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use PHPUnit\Framework\TestCase;

/**
 * What the mapped terms mean once they are a value object — no model call
 * needed, because since the schema does the parsing these are plain DTOs.
 */
final class OfferTermsTest extends TestCase
{
    /** An offer with no terms at all still yields an offer, just an empty one. */
    public function testAbsentTermsAreNotAFailure(): void
    {
        $offer = (new OfferTerms())->toOffer(1000.0);

        self::assertSame(1000.0, $offer->orderTotalNet);
        self::assertNull($offer->price->discountPercent);
        self::assertNull($offer->price->linePricesNet);
    }

    /**
     * A schema that permits an array gets an empty one sooner or later. It
     * means the same as null, and reading it as a per-line offer would make
     * the contradiction check below fire on a plain quote-wide discount, and
     * leave LinePriceOfferCheck bounding nothing.
     */
    public function testAnEmptyLinePriceListMeansNoPerLineOffer(): void
    {
        $terms = new OfferTerms(discountPercent: 5.0, linePricesNet: []);

        self::assertFalse($terms->contradictory());
        self::assertNull($terms->toOffer(1000.0)->price->linePricesNet);
        self::assertSame(5.0, $terms->toOffer(1000.0)->price->discountPercent);
    }

    public function testAnOfferCarryingBothADiscountAndLinePricesIsUnusable(): void
    {
        // OfferApplier writes the lines and drops the discount, so the offer
        // the buyer is told about would not be the one the database holds.
        $response = new NegotiateResponse(
            action: NegotiationAction::Offer,
            message: 'both',
            terms: new OfferTerms(discountPercent: 5.0, linePricesNet: [new QuoteLinePrice('line-1', 95.0)]),
        );

        $this->expectException(ModelUnavailable::class);

        $response->toOffer(1000.0);
    }
}
