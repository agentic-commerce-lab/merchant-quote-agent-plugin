<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use PHPUnit\Framework\TestCase;

final class QuoteUpdateTest extends TestCase
{
    public function testEmptyUpdateTouchesNothing(): void
    {
        self::assertTrue((new QuoteUpdate())->isEmpty());
    }

    public function testDiscountOnlyUpdateIsNotEmpty(): void
    {
        $update = new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 10.0));

        self::assertFalse($update->isEmpty());
        self::assertNull($update->expiresAt);
        self::assertNull($update->customFields);
    }
}
