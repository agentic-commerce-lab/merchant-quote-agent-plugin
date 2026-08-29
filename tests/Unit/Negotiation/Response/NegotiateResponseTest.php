<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use PHPUnit\Framework\TestCase;

final class NegotiateResponseTest extends TestCase
{
    public function testItReadsAQuoteWideOffer(): void
    {
        $response = NegotiateResponse::read('{"action":"offer","discount_percent":7.5,"message":"Here is 7.5% off."}');

        self::assertFalse($response->escalate);
        self::assertSame('Here is 7.5% off.', $response->message);

        $offer = $response->toOffer(1000.0);
        self::assertSame(7.5, $offer->price->discountPercent);
        self::assertSame(1000.0, $offer->orderTotalNet);
        self::assertNull($offer->price->linePricesNet);
    }

    public function testItReadsAPerLineOffer(): void
    {
        $json = '{"action":"offer","line_prices":[{"line_item_id":"line-1","unit_price_net":45.5}],"message":"ok"}';

        $offer = NegotiateResponse::read($json)->toOffer(1000.0);

        self::assertNotNull($offer->price->linePricesNet);
        self::assertCount(1, $offer->price->linePricesNet);
        self::assertSame('line-1', $offer->price->linePricesNet[0]->lineItemId);
        self::assertSame(45.5, $offer->price->linePricesNet[0]->unitPriceNet);
    }

    public function testItReadsNonPriceTerms(): void
    {
        $json =
            '{"action":"offer","free_shipping":true,"expedited":false,"committed_lead_time_days":3,'
            . '"payment_term":"net_60","net_days":60,"deposit_percent":15,"message":"ok"}';

        $offer = NegotiateResponse::read($json)->toOffer(1000.0);

        self::assertTrue($offer->delivery->freeShipping);
        self::assertSame(3, $offer->delivery->committedLeadTimeDays);
        self::assertSame(PaymentTerm::Net60, $offer->payment->paymentTerm);
        self::assertSame(60, $offer->payment->netDays);
        self::assertSame(15.0, $offer->payment->depositPercent);
    }

    public function testTheModelMayDeclineAndSayWhy(): void
    {
        $response = NegotiateResponse::read(
            '{"action":"escalate","escalation_reason":"buyer wants terms I cannot offer","message":""}',
        );

        self::assertTrue($response->escalate);
        self::assertSame('buyer wants terms I cannot offer', $response->escalationReason);
    }

    public function testAnUnknownActionIsUnusable(): void
    {
        $this->expectException(ModelUnavailable::class);

        NegotiateResponse::read('{"action":"maybe","message":"hmm"}');
    }

    public function testAnOfferCarryingBothADiscountAndLinePricesIsUnusable(): void
    {
        // OfferApplier writes the lines and drops the discount, so the offer
        // the buyer is told about would not be the one the database holds.
        $this->expectException(ModelUnavailable::class);

        NegotiateResponse::read(
            '{"action":"offer","discount_percent":5,'
            . '"line_prices":[{"line_item_id":"line-1","unit_price_net":95}],"message":"both"}',
        );
    }

    public function testUnparseableJsonIsUnusable(): void
    {
        $this->expectException(ModelUnavailable::class);

        NegotiateResponse::read('Sure! Here is my offer: 10% off.');
    }
}
