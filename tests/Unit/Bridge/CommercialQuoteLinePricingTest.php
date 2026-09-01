<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteLinePricing;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;

/**
 * `requireProductId()` and `requestedPrice()` are pure, array-in/scalar-out —
 * no container, no shop, no SwagCommercial needed. The cases here are exactly
 * the agent-supplied-input branches the class's complexity suppression cites.
 */
final class CommercialQuoteLinePricingTest extends TestCase
{
    public function testRequireProductIdReturnsAPresentId(): void
    {
        self::assertSame('prod-1', $this->pricing()->requireProductId(['product_id' => 'prod-1'], 0));
    }

    public function testRequireProductIdRejectsAMissingId(): void
    {
        $this->expectException(ValidationException::class);

        $this->pricing()->requireProductId([], 0);
    }

    public function testRequireProductIdRejectsAnEmptyId(): void
    {
        $this->expectException(ValidationException::class);

        $this->pricing()->requireProductId(['product_id' => ''], 0);
    }

    public function testRequestedPriceReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->pricing()->requestedPrice([], 'path'));
    }

    public function testRequestedPriceReturnsNullWhenEmptyString(): void
    {
        self::assertNull($this->pricing()->requestedPrice(['requested_unit_price' => ''], 'path'));
    }

    public function testRequestedPriceParsesANumericValue(): void
    {
        self::assertSame(9.99, $this->pricing()->requestedPrice(['requested_unit_price' => '9.99'], 'path'));
    }

    public function testRequestedPriceRejectsANonNumericValue(): void
    {
        $this->expectException(ValidationException::class);

        $this->pricing()->requestedPrice(['requested_unit_price' => 'free'], 'path');
    }

    /**
     * `$quoteLineItemRoute` defaults to null, so the write path guards
     * itself the same way the gateway's routes do — a missing commercial
     * service surfaces as a named exception, not a fatal error.
     */
    public function testApplyRequestedPricesThrowsWhenTheRouteIsUnavailable(): void
    {
        $quote = new class {
            public function getLineItems(): array
            {
                return [new class {
                    public function getId(): string
                    {
                        return 'line-item-1';
                    }

                    public function getProductId(): string
                    {
                        return 'prod-1';
                    }
                }];
            }
        };

        $this->expectException(UnsupportedCapabilityException::class);

        $this->pricing()->applyRequestedPrices(
            'quote-1',
            $quote,
            ['prod-1' => 9.99],
            $this->createMock(SalesChannelContext::class),
        );
    }

    private function pricing(): CommercialQuoteLinePricing
    {
        return new CommercialQuoteLinePricing();
    }
}
