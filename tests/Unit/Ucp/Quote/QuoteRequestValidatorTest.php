<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteRequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

final class QuoteRequestValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'non-object line item' => [['line_items' => ['product-id']]];
        yield 'missing product id' => [['line_items' => [['quantity' => 1]]]];
        yield 'missing quantity' => [['line_items' => [['product_id' => 'product-id']]]];
        yield 'numeric string quantity' => [['line_items' => [['product_id' => 'product-id', 'quantity' => '2']]]];
        yield 'zero quantity' => [['line_items' => [['product_id' => 'product-id', 'quantity' => 0]]]];
        yield 'string requested price' => [[
            'line_items' => [['product_id' => 'product-id', 'quantity' => 1, 'requested_unit_price' => '9.99']],
        ]];
        yield 'negative requested price' => [[
            'line_items' => [['product_id' => 'product-id', 'quantity' => 1, 'requested_unit_price' => -1]],
        ]];
        yield 'non-string comment' => [[
            'line_items' => [['product_id' => 'product-id', 'quantity' => 1]],
            'comment' => 42,
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function testItRejectsPayloadValuesThatDoNotMatchThePublishedSchema(array $payload): void
    {
        $validator = new QuoteRequestValidator();

        $this->expectException(ValidationException::class);
        $validator->lineItems($payload, true);
        $validator->comment($payload);
    }

    #[Test]
    public function testItPreservesAValidRequestPayload(): void
    {
        $payload = [
            'line_items' => [[
                'product_id' => 'product-id',
                'quantity' => 2,
                'requested_unit_price' => 9.99,
            ]],
            'comment' => 'Volume price, please.',
        ];
        $validator = new QuoteRequestValidator();

        self::assertSame($payload['line_items'], $validator->lineItems($payload, true));
        self::assertSame($payload['comment'], $validator->comment($payload));
    }
}
