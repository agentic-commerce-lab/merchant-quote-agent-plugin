<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the negotiate prompt's answer has to survive on the way from JSON to a
 * ProposedOffer. There is no reader class in between any more, so this covers
 * the generated schema and the mapping onto the DTO; OfferTermsTest covers what
 * the terms then mean.
 */
final class NegotiateResponseTest extends TestCase
{
    /** @throws ModelUnavailable */
    private static function read(string $json): NegotiateResponse
    {
        return ScriptedClient::returning([$json])->object(
            NegotiationFixture::modelAccess(),
            'sys',
            'usr',
            NegotiateResponse::class,
        );
    }

    public function testItReadsAQuoteWideOffer(): void
    {
        $response = self::read('{"action":"offer","message":"Here is 7.5% off.","terms":{"discountPercent":7.5}}');

        self::assertFalse($response->escalates());
        self::assertSame('Here is 7.5% off.', $response->message);

        $offer = $response->toOffer(1000.0);
        self::assertSame(7.5, $offer->price->discountPercent);
        self::assertSame(1000.0, $offer->orderTotalNet);
        self::assertNull($offer->price->linePricesNet);
    }

    public function testItReadsAPerLineOffer(): void
    {
        $json = '{"action":"offer","message":"ok","terms":{"linePricesNet":[{"lineItemId":"line-1","unitPriceNet":45.5}]}}';

        $offer = self::read($json)->toOffer(1000.0);

        self::assertNotNull($offer->price->linePricesNet);
        self::assertCount(1, $offer->price->linePricesNet);
        self::assertSame('line-1', $offer->price->linePricesNet[0]->lineItemId);
        self::assertSame(45.5, $offer->price->linePricesNet[0]->unitPriceNet);
    }

    public function testItReadsNonPriceTerms(): void
    {
        $json =
            '{"action":"offer","message":"ok","terms":{'
            . '"delivery":{"freeShipping":true,"expedited":false,"committedLeadTimeDays":3},'
            . '"payment":{"paymentTerm":"net_60","netDays":60,"depositPercent":15}}}';

        $offer = self::read($json)->toOffer(1000.0);

        self::assertTrue($offer->delivery->freeShipping);
        self::assertSame(3, $offer->delivery->committedLeadTimeDays);
        self::assertSame(PaymentTerm::Net60, $offer->payment->paymentTerm);
        self::assertSame(60, $offer->payment->netDays);
        self::assertSame(15.0, $offer->payment->depositPercent);
    }

    public function testTheModelMayDeclineAndSayWhy(): void
    {
        $response = self::read(
            '{"action":"escalate","escalationReason":"buyer wants terms I cannot offer","message":""}',
        );

        self::assertTrue($response->escalates());
        self::assertSame('buyer wants terms I cannot offer', $response->escalationReason);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusable(): iterable
    {
        yield 'an action outside the enum' => ['{"action":"maybe","message":"hmm"}'];
        // `action` carries no default, so there is nothing to fall back to —
        // and defaulting it would answer a buyer the model never answered.
        yield 'no action at all' => ['{"message":"hmm"}'];
        yield 'not json' => ['Sure! Here is my offer: 10% off.'];
        yield 'a term outside the enum' => ['{"action":"offer","terms":{"payment":{"paymentTerm":"net_45"}}}'];
    }

    #[DataProvider('unusable')]
    public function testAnUnusableResponseEscalatesRatherThanGuessing(string $json): void
    {
        $this->expectException(ModelUnavailable::class);

        self::read($json);
    }
}
