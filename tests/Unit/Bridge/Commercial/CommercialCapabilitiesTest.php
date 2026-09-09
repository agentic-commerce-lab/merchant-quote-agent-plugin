<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * The object is four booleans, so the only things worth pinning are the two
 * named constructors — they are what every other test in the suite builds its
 * fixtures from, and a silent flip in either would make a legacy test assert
 * modern behaviour while still passing.
 */
final class CommercialCapabilitiesTest extends TestCase
{
    public function testModernHasEveryCapability(): void
    {
        $capabilities = CommercialCapabilities::modern();

        self::assertTrue($capabilities->lineItemAsks);
        self::assertTrue($capabilities->softDeleteLines);
        self::assertTrue($capabilities->lineScopedComments);
        self::assertTrue($capabilities->draftBeforeSend);
    }

    public function testLegacyHasNone(): void
    {
        $capabilities = CommercialCapabilities::legacy();

        self::assertFalse($capabilities->lineItemAsks);
        self::assertFalse($capabilities->softDeleteLines);
        self::assertFalse($capabilities->lineScopedComments);
        self::assertFalse($capabilities->draftBeforeSend);
    }

    public function testCapabilitiesAreIndependent(): void
    {
        $capabilities = new CommercialCapabilities(
            lineItemAsks: false,
            softDeleteLines: true,
            lineScopedComments: false,
            draftBeforeSend: true,
        );

        self::assertFalse($capabilities->lineItemAsks);
        self::assertTrue($capabilities->softDeleteLines);
        self::assertFalse($capabilities->lineScopedComments);
        self::assertTrue($capabilities->draftBeforeSend);
    }
}
