<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Migration\Migration1789400003AddStrategyVersionToDecision;
use PHPUnit\Framework\TestCase;

final class AddStrategyVersionToDecisionMigrationTest extends TestCase
{
    public function testTheTimestampIsExact(): void
    {
        // Exact, not merely "greater than the previous migration": a later
        // migration accidentally renumbered into a collision would otherwise
        // pass silently.
        self::assertSame(1789400003, (new Migration1789400003AddStrategyVersionToDecision())->getCreationTimestamp());
    }
}
