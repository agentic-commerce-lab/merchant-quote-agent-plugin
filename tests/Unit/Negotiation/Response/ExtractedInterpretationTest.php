<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The extract prompt's answer is mapped straight onto CommentInterpretation —
 * there is no reader class in between any more, so what this covers is the
 * contract between the generated JSON schema and the policy DTOs. A field
 * renamed or retyped in Policy\Data changes both at once, which is the point;
 * these tests are what catches it changing into something unusable.
 */
final class ExtractedInterpretationTest extends TestCase
{
    /** @throws ModelUnavailable */
    private static function interpret(string $json): CommentInterpretation
    {
        return ScriptedClient::returning([$json])->object(
            NegotiationFixture::modelAccess(),
            'sys',
            'usr',
            CommentInterpretation::class,
        );
    }

    public function testItReadsTheFullDocumentedShape(): void
    {
        $json = <<<'JSON'
            {
              "price": {"additionalDiscountPercent": 5, "bestPriceRequested": false},
              "structural": {
                "lineChanges": [{"lineItemId": "line-1", "quantity": 10, "targetUnitPrice": 45.5, "remove": false}],
                "addProducts": [],
                "validityUntilIsoDate": "2026-09-30"
              },
              "clarificationQuestions": ["when do you need it?"],
              "humanReviewRequests": [],
              "negotiation": {
                "delivery": {"freeShipping": true, "expedited": false, "requestedLeadTimeDays": 5},
                "payment": {"requestedTerm": "net_30", "requestedNetDays": 30, "requestedDepositPercent": 10},
                "bundle": {"requested": false}
              }
            }
            JSON;

        $interpretation = self::interpret($json);

        self::assertSame(5.0, $interpretation->price->additionalDiscountPercent);
        self::assertFalse($interpretation->price->bestPriceRequested);
        self::assertCount(1, $interpretation->structural->lineChanges);
        self::assertSame('2026-09-30', $interpretation->structural->validityUntilIsoDate);
        self::assertSame(['when do you need it?'], $interpretation->clarificationQuestions);
        self::assertTrue($interpretation->negotiation?->delivery?->freeShipping);
        self::assertSame(5, $interpretation->negotiation?->delivery?->requestedLeadTimeDays);
        self::assertSame(PaymentTerm::Net30, $interpretation->negotiation?->payment?->requestedTerm);
    }

    /**
     * A product to add is the one field the old hand-written rekeying got
     * wrong: it mapped the wire's `product` onto a DTO field called
     * `productRef`, so any non-empty list failed to map at all. Deriving the
     * schema from the DTO is what makes that class of mismatch impossible.
     */
    public function testAProductAdditionMapsOntoTheFieldTheDtoActuallyHas(): void
    {
        $interpretation = self::interpret(
            '{"structural":{"addProducts":[{"productRef":"SW10001","quantity":3,"targetUnitPrice":null}]}}',
        );

        self::assertCount(1, $interpretation->structural->addProducts);
        self::assertSame('SW10001', $interpretation->structural->addProducts[0]->productRef);
        self::assertSame(3, $interpretation->structural->addProducts[0]->quantity);
    }

    public function testAMinimalResponseIsAnEmptyInterpretationNotAFailure(): void
    {
        // "The buyer asked for nothing we can act on" is a valid answer and
        // must reach the deciders, which then escalate or no-op on their own.
        $interpretation = self::interpret('{"price":{"bestPriceRequested":false}}');

        self::assertNull($interpretation->price->additionalDiscountPercent);
        self::assertSame([], $interpretation->humanReviewRequests);
    }

    public function testANullNegotiationBlockIsAccepted(): void
    {
        self::assertNull(self::interpret('{"negotiation":null}')->negotiation);
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusable(): iterable
    {
        yield 'not json' => ['I cannot help with that.'];
        yield 'truncated' => ['{"price": {"additionalDiscountPercent": 5'];
        yield 'a json array, not an object' => ['[1,2,3]'];
        yield 'wrong type for a number' => ['{"price":{"additionalDiscountPercent":"five"}}'];
        yield 'unknown payment term' => ['{"negotiation":{"payment":{"requestedTerm":"net_45"}}}'];
    }

    #[DataProvider('unusable')]
    public function testAnUnusableResponseEscalatesRatherThanGuessing(string $json): void
    {
        $this->expectException(ModelUnavailable::class);

        self::interpret($json);
    }
}
