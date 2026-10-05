<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\InterpretationPayload;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryAsk;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedProductAddition;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationAsks;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentAsk;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\StructuralAsks;
use PHPUnit\Framework\TestCase;

final class InterpretationPayloadTest extends TestCase
{
    public function testItSurvivesARoundTrip(): void
    {
        // Populate EVERY field, including every nested value object and both
        // branches of every nullable. A round trip over a half-empty
        // interpretation proves nothing about the field somebody adds next.
        $interpretation = $this->fullyPopulated();

        $restored = InterpretationPayload::from(InterpretationPayload::of($interpretation));

        self::assertEquals($interpretation, $restored);
    }

    public function testItSurvivesARoundTripWithNoNegotiationAsks(): void
    {
        // The other branch of the top-level nullable: negotiation itself is
        // null, not merely empty.
        $interpretation = new CommentInterpretation(
            price: new PriceAsk(additionalDiscountPercent: 3.5, bestPriceRequested: false, targetTotal: 900.0),
            structural: new StructuralAsks(
                lineChanges: [new InterpretedLineChange(lineItemId: 'line-9')],
                addProducts: [],
                validityUntilIsoDate: null,
            ),
            clarificationQuestions: [],
            humanReviewRequests: ['escalate this one'],
            negotiation: null,
        );

        $restored = InterpretationPayload::from(InterpretationPayload::of($interpretation));

        self::assertEquals($interpretation, $restored);
    }

    public function testItRefusesAShapeItDoesNotRecognise(): void
    {
        self::assertNull(InterpretationPayload::from(['unexpected' => true]));
        self::assertNull(InterpretationPayload::from([]));
    }

    public function testItRefusesAWronglyTypedField(): void
    {
        $payload = InterpretationPayload::of($this->fullyPopulated());
        $payload['price'] = 'not an object';

        self::assertNull(InterpretationPayload::from($payload));
    }

    private function fullyPopulated(): CommentInterpretation
    {
        return new CommentInterpretation(
            price: new PriceAsk(additionalDiscountPercent: 12.5, bestPriceRequested: true, targetTotal: 1500.0),
            structural: new StructuralAsks(
                lineChanges: [
                    new InterpretedLineChange(lineItemId: 'line-1', quantity: 4, targetUnitPrice: 19.99, remove: false),
                    new InterpretedLineChange(
                        lineItemId: 'line-2',
                        quantity: null,
                        targetUnitPrice: null,
                        remove: true,
                    ),
                ],
                addProducts: [
                    new InterpretedProductAddition(productRef: 'sku-42', quantity: 3, targetUnitPrice: 7.5),
                ],
                validityUntilIsoDate: '2026-12-31',
            ),
            clarificationQuestions: ['which colour do you want?', 'how many units?'],
            humanReviewRequests: ['buyer sounds upset'],
            negotiation: new NegotiationAsks(
                delivery: new DeliveryAsk(
                    freeShipping: true,
                    shippingCostNet: 0.0,
                    expedited: false,
                    requestedLeadTimeDays: 5,
                ),
                payment: new PaymentAsk(
                    requestedTerm: PaymentTerm::Net30,
                    requestedNetDays: 30,
                    requestedDepositPercent: 10.0,
                ),
            ),
        );
    }
}
