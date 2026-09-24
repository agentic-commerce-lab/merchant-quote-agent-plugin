<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Migration\Migration1789800000CreateQuoteAgentTrace;
use PHPUnit\Framework\TestCase;

final class CreateQuoteAgentTraceMigrationTest extends TestCase
{
    public function testTheTimestampIsExact(): void
    {
        self::assertSame(1789800000, (new Migration1789800000CreateQuoteAgentTrace())->getCreationTimestamp());
    }
}
