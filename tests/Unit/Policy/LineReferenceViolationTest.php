<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\LineReferenceViolation;
use PHPUnit\Framework\TestCase;

final class LineReferenceViolationTest extends TestCase
{
    public function testItAllowsRoundedCentPricesWhenFloorHasFractionalCents(): void
    {
        // 183.57 with 15% discount has unrounded floor 156.0345
        // Currency rounding rounds this to 156.03.
        $lineId = 'line-1';
        $reference = [
            $lineId => new QuoteLineSnapshot(
                identity: new QuoteLineIdentity($lineId, 'Heavy Duty Steel Structure-AL'),
                quantity: 10,
                unitPriceNet: 183.57,
                totalNet: 1835.70,
            ),
        ];
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 15.0));
        $floorFactor = 1 - (15.0 / 100); // 0.85

        $validPrice = new QuoteLinePrice($lineId, 156.03);
        $violation = LineReferenceViolation::check($validPrice, $reference, $floorFactor, $policy);
        self::assertNull($violation, '156.03 EUR should be approved within money epsilon tolerance');

        $undercutPrice = new QuoteLinePrice($lineId, 156.02);
        $violation = LineReferenceViolation::check($undercutPrice, $reference, $floorFactor, $policy);
        self::assertNotNull($violation, '156.02 EUR should be rejected as exceeding the 15% limit');
    }
}
