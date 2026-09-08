<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Act;

use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Act\OutOfRangeSequence;
use PHPUnit\Framework\TestCase;

final class ActKeyTest extends TestCase
{
    public function testItZeroPadsSoLexicalOrderIsChronological(): void
    {
        self::assertSame('a2cn_act_0001_b', ActKey::for(1, ActRole::Buyer));
        self::assertSame('a2cn_act_0003_s', ActKey::for(3, ActRole::Seller));
        self::assertSame('a2cn_act_0042_s', ActKey::for(42, ActRole::Seller));

        $keys = [ActKey::for(10, ActRole::Seller), ActKey::for(2, ActRole::Seller)];
        sort($keys);
        self::assertSame([ActKey::for(2, ActRole::Seller), ActKey::for(10, ActRole::Seller)], $keys);
    }

    public function testTheTwoRolesCanNeverTargetTheSameKey(): void
    {
        self::assertNotSame(ActKey::for(3, ActRole::Buyer), ActKey::for(3, ActRole::Seller));
    }

    public function testItRecognizesOnlyActKeys(): void
    {
        self::assertTrue(ActKey::isActKey('a2cn_act_0001_b'));
        self::assertFalse(ActKey::isActKey(ActKey::SESSION_KEY));
        self::assertFalse(ActKey::isActKey('merchant_quote_agent_serviced'));
    }

    public function testItRefusesToEmitAnUnsortableKeyBelowTheRange(): void
    {
        $this->expectException(OutOfRangeSequence::class);

        ActKey::for(0, ActRole::Buyer);
    }

    public function testItRefusesToEmitAnUnsortableKeyAboveTheRange(): void
    {
        $this->expectException(OutOfRangeSequence::class);

        ActKey::for(ActKey::MAX_SEQUENCE + 1, ActRole::Buyer);
    }
}
