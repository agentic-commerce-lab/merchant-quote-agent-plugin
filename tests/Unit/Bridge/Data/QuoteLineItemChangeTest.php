<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use PHPUnit\Framework\TestCase;

final class QuoteLineItemChangeTest extends TestCase
{
    public function testNullFieldsMeanUntouched(): void
    {
        $change = new QuoteLineItemChange(lineItemId: 'l1', unitPriceNet: 90.0);

        self::assertSame('l1', $change->lineItemId);
        self::assertSame(90.0, $change->unitPriceNet);
        self::assertNull($change->quantity);
        self::assertNull($change->remove);
        self::assertTrue($change->touchesPrice());
    }

    public function testRemovalDoesNotTouchPrice(): void
    {
        $change = new QuoteLineItemChange(lineItemId: 'l1', remove: true);

        self::assertFalse($change->touchesPrice());
        self::assertTrue($change->isRemoval());
    }
}
