<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Migration\Migration1789900000AddGrossTotalsToDecision;
use PHPUnit\Framework\TestCase;

final class AddGrossTotalsToDecisionMigrationTest extends TestCase
{
    public function testTheTimestampIsExact(): void
    {
        self::assertSame(1789900000, (new Migration1789900000AddGrossTotalsToDecision())->getCreationTimestamp());
    }
}
