<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\CommercialQuoteSnapshotMapper;
use PHPUnit\Framework\TestCase;

/**
 * The mapper reads an untyped SwagCommercial entity, so a released shop differs
 * from trunk by a method that is simply not there. The fixtures below are the
 * two entity shapes: one with `getRequestedPrice()`, one without. Calling the
 * absent one is a fatal `Error`, not a warning, and it fires on every
 * buyer-side read — which is why this is gated rather than defaulted.
 */
final class CommercialQuoteSnapshotMapperTest extends TestCase
{
    public function testAModernShopPublishesTheBuyersAsk(): void
    {
        $snapshot = (new CommercialQuoteSnapshotMapper(CommercialCapabilities::modern()))->toSnapshot(
            self::quote(self::modernLine()),
        );

        self::assertSame(7.5, $snapshot->lineItems[0]['requested_unit_price']);
    }

    public function testALegacyShopPublishesNullWithoutCallingTheMissingGetter(): void
    {
        $snapshot = (new CommercialQuoteSnapshotMapper(CommercialCapabilities::legacy()))->toSnapshot(
            self::quote(self::legacyLine()),
        );

        self::assertNull($snapshot->lineItems[0]['requested_unit_price']);
        self::assertSame('line-1', $snapshot->lineItems[0]['id']);
        self::assertSame(12.0, $snapshot->lineItems[0]['unit_price']);
    }

    private static function legacyLine(): object
    {
        return new class {
            public function getId(): string
            {
                return 'line-1';
            }

            public function getProductId(): ?string
            {
                return 'product-1';
            }

            public function getLabel(): string
            {
                return 'Widget';
            }

            public function getQuantity(): int
            {
                return 3;
            }

            public function getUnitPrice(): float
            {
                return 12.0;
            }

            public function getTotalPrice(): float
            {
                return 36.0;
            }
        };
    }

    private static function modernLine(): object
    {
        return new class {
            public function getId(): string
            {
                return 'line-1';
            }

            public function getProductId(): ?string
            {
                return 'product-1';
            }

            public function getLabel(): string
            {
                return 'Widget';
            }

            public function getQuantity(): int
            {
                return 3;
            }

            public function getUnitPrice(): float
            {
                return 12.0;
            }

            public function getTotalPrice(): float
            {
                return 36.0;
            }

            public function getRequestedPrice(): ?float
            {
                return 7.5;
            }
        };
    }

    private static function quote(object $line): object
    {
        /**
         * @mago-expect lint:too-many-methods
         * Stands in for SwagCommercial's real quote entity, which is exactly
         * this wide (see CommercialQuoteSnapshotMapper::toSnapshot()) — paring
         * the fixture down would stop matching the shape being guarded
         * against.
         */
        return new class($line) {
            public function __construct(
                private readonly object $line,
            ) {}

            public function getId(): string
            {
                return 'quote-1';
            }

            public function getQuoteNumber(): string
            {
                return '10001';
            }

            public function getStateMachineState(): ?object
            {
                return null;
            }

            public function getExpirationDate(): ?\DateTimeInterface
            {
                return null;
            }

            public function getCurrency(): ?object
            {
                return null;
            }

            public function getAmountTotal(): float
            {
                return 36.0;
            }

            public function getAmountNet(): float
            {
                return 30.25;
            }

            public function getTaxStatus(): string
            {
                return 'gross';
            }

            /** @return list<object> */
            public function getLineItems(): array
            {
                return [$this->line];
            }

            /** @return list<object> */
            public function getComments(): array
            {
                return [];
            }
        };
    }
}
