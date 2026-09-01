<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * `toArray()` is the wire format published at
 * `.well-known/ucp/schemas/quote.openapi.json` — a renamed or dropped key
 * here is a contract break no other test catches.
 */
final class QuoteSnapshotTest extends TestCase
{
    public function testItPublishesTheContractKeysWithExpirationAndPriceSemantics(): void
    {
        $payload = QuoteCapabilityFixture::snapshot()->toArray();

        self::assertSame('quote-id', $payload['id']);
        self::assertSame('1030', $payload['quote_number']);
        self::assertSame('replied', $payload['state']);
        self::assertArrayHasKey('expiration_date', $payload);
        self::assertSame('2026-08-15T00:00:00+00:00', $payload['expiration_date']);
        self::assertSame('EUR', $payload['currency']);
        self::assertSame(['gross' => 119.0, 'net' => 100.0, 'tax_status' => 'gross'], $payload['totals']);
        self::assertSame(9.5, $payload['line_items'][0]['requested_unit_price']);
        self::assertArrayHasKey('comments', $payload);
        self::assertArrayNotHasKey('order', $payload);
    }

    public function testExpirationDateIsPresentButNullWhenNotSet(): void
    {
        $payload = (new QuoteSnapshot('quote-id', '1030', 'draft', null, 'EUR', null, null, null, [], []))->toArray();

        self::assertArrayHasKey('expiration_date', $payload);
        self::assertNull($payload['expiration_date']);
    }

    public function testTheOrderBlockAppearsOnlyOnceAccepted(): void
    {
        $accepted = new QuoteSnapshot(
            'quote-id',
            '1030',
            'accepted',
            null,
            'EUR',
            119.0,
            100.0,
            'gross',
            [],
            [],
            'order-id',
            '10001',
        );

        self::assertSame(['id' => 'order-id', 'order_number' => '10001'], $accepted->toArray()['order']);
    }
}
