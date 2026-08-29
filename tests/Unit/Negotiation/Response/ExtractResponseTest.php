<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Negotiation\Response\ExtractResponse;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtractResponseTest extends TestCase
{
    public function testItReadsTheFullDocumentedShape(): void
    {
        $json = <<<'JSON'
            {
              "additional_discount_percent": 5,
              "best_price_requested": false,
              "line_changes": [{"line_item_id": "line-1", "quantity": 10, "target_unit_price": 45.5, "remove": false}],
              "add_products": [],
              "validity_until": "2026-09-30",
              "clarification_questions": ["when do you need it?"],
              "human_review_requests": [],
              "negotiation": {
                "delivery": {"free_shipping": true, "expedited": false, "requested_lead_time_days": 5},
                "payment": {"requested_term": "net_30", "requested_net_days": 30, "requested_deposit_percent": 10},
                "bundle": {"requested": false}
              }
            }
            JSON;

        $interpretation = ExtractResponse::toInterpretation($json);

        self::assertSame(5.0, $interpretation->price->additionalDiscountPercent);
        self::assertFalse($interpretation->price->bestPriceRequested);
        self::assertCount(1, $interpretation->structural->lineChanges);
        self::assertSame('2026-09-30', $interpretation->structural->validityUntilIsoDate);
        self::assertSame(['when do you need it?'], $interpretation->clarificationQuestions);
        self::assertTrue($interpretation->negotiation?->delivery?->freeShipping);
        self::assertSame(5, $interpretation->negotiation?->delivery?->requestedLeadTimeDays);
        self::assertSame(PaymentTerm::Net30, $interpretation->negotiation?->payment?->requestedTerm);
    }

    public function testAMinimalResponseIsAnEmptyInterpretationNotAFailure(): void
    {
        // "The buyer asked for nothing we can act on" is a valid answer and
        // must reach the deciders, which then escalate or no-op on their own.
        $interpretation = ExtractResponse::toInterpretation('{"best_price_requested": false}');

        self::assertNull($interpretation->price->additionalDiscountPercent);
        self::assertSame([], $interpretation->humanReviewRequests);
    }

    public function testANullNegotiationBlockIsAccepted(): void
    {
        $interpretation = ExtractResponse::toInterpretation('{"negotiation": null}');

        self::assertNull($interpretation->negotiation);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusable(): iterable
    {
        yield 'not json' => ['I cannot help with that.'];
        yield 'truncated' => ['{"additional_discount_percent": 5'];
        yield 'a json array, not an object' => ['[1,2,3]'];
        yield 'wrong type for a number' => ['{"additional_discount_percent": "five"}'];
        yield 'unknown payment term' => ['{"negotiation":{"payment":{"requested_term":"net_45"}}}'];
    }

    #[DataProvider('unusable')]
    public function testAnUnusableResponseEscalatesRatherThanGuessing(string $json): void
    {
        $this->expectException(ModelUnavailable::class);

        ExtractResponse::toInterpretation($json);
    }
}
