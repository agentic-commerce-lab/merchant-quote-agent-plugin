<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * `toArray()` is the wire format published at
 * `.well-known/ucp/schemas/quote.openapi.json` — every key it emits is
 * checked against that file's `Quote` schema below, so a renamed or dropped
 * key here is a contract break this test actually catches.
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
        self::assertEmpty(
            array_diff(array_keys($payload), self::quoteSchemaPropertyNames()),
            'toArray() emits a key the published Quote schema does not declare.',
        );
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
        self::assertEmpty(
            array_diff(array_keys($accepted->toArray()), self::quoteSchemaPropertyNames()),
            'toArray() emits a key the published Quote schema does not declare.',
        );
    }

    public function testWithOrderAttachesTheOrderWithoutChangingAnyOtherField(): void
    {
        $snapshot = QuoteCapabilityFixture::snapshot();
        $withOrder = $snapshot->withOrder('order-id', '10001');

        self::assertSame($snapshot->id, $withOrder->id);
        self::assertSame($snapshot->state, $withOrder->state);
        self::assertSame($snapshot->lineItems, $withOrder->lineItems);
        self::assertSame('order-id', $withOrder->orderId);
        self::assertSame('10001', $withOrder->orderNumber);
    }

    /**
     * The published `Quote` schema's own top-level property names, read from
     * the same file the capability descriptor advertises — not a second
     * hardcoded list that could drift from it on its own.
     *
     * @return list<string>
     */
    private static function quoteSchemaPropertyNames(): array
    {
        $path = \dirname(__DIR__, 4) . '/src/Resources/schema/quote.openapi.json';
        /** @var array{components: array{schemas: array{Quote: array{properties: array<string, mixed>}}}} $schema */
        $schema = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return array_keys($schema['components']['schemas']['Quote']['properties']);
    }
}
