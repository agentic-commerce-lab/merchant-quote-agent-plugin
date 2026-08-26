<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\MoneyMath;
use PHPUnit\Framework\TestCase;

final class MoneyMathTest extends TestCase
{
    /**
     * PHP's round() breaks ties away from zero; JS's Math.round() (what the
     * ported quote-decision.ts uses) breaks ties toward +Infinity. They only
     * diverge on negative exact-half-cent values — this is the case that
     * proves roundMoney() replicates JS, not PHP's native rounding.
     */
    public function testRoundsNegativeHalfCentTowardPositiveInfinityLikeJs(): void
    {
        self::assertSame(0.0, MoneyMath::roundMoney(-0.005));
    }

    public function testRoundsPositiveHalfCentUp(): void
    {
        self::assertSame(0.01, MoneyMath::roundMoney(0.005));
    }
}
