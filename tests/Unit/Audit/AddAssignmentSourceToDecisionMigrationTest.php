<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Migration\Migration1789600001AddAssignmentSourceToDecision;
use PHPUnit\Framework\TestCase;

final class AddAssignmentSourceToDecisionMigrationTest extends TestCase
{
    public function testTheTimestampIsExact(): void
    {
        // Exact, not merely "greater than the previous migration": a later
        // migration accidentally renumbered into a collision would otherwise
        // pass silently.
        self::assertSame(1789600001, (new Migration1789600001AddAssignmentSourceToDecision())->getCreationTimestamp());
    }
}
