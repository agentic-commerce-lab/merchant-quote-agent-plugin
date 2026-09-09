<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteLinePricing;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;

/**
 * A released SwagCommercial has no `QuoteLineItemRoute`, so there is nowhere to
 * put a per-unit ask. `CommercialQuoteLinePricing::isAvailable()` already
 * reports that; what this pins is that an agent sending one gets a 422 naming
 * the reason rather than a silently discarded price.
 *
 * The gateway itself needs a shop to construct, so the boundary is asserted on
 * the collaborator that owns the decision. LegacyBuyerFlowTest drives the same
 * path end to end against the 6.7.12 shop.
 */
final class CommercialQuoteLinePricingLegacyTest extends TestCase
{
    public function testLinePricingReportsUnavailableWithoutTheRoute(): void
    {
        self::assertFalse((new CommercialQuoteLinePricing())->isAvailable());
    }

    public function testLinePricingReportsAvailableWithTheRoute(): void
    {
        self::assertTrue((new CommercialQuoteLinePricing(new \stdClass()))->isAvailable());
    }

    public function testACounterPriceIsRejectedWhenLinePricingIsUnavailable(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not support per-line price asks');

        (new CommercialQuoteLinePricing())->assertCanPriceLines();
    }

    public function testAssertingIsANoOpWhenLinePricingIsAvailable(): void
    {
        (new CommercialQuoteLinePricing(new \stdClass()))->assertCanPriceLines();

        $this->expectNotToPerformAssertions();
    }
}
