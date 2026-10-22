<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Improvement\RunStatus;
use PHPUnit\Framework\TestCase;

final class RunStatusTest extends TestCase
{
    public function testItHasNoNotDueCase(): void
    {
        // A not-due tick writes NO row: the admin derives "next due" from the
        // last completed run plus the cadence. A nightly stream of "not due"
        // rows would bury the nights that did something.
        self::assertSame(
            ['running', 'completed', 'failed', 'no_data'],
            array_map(static fn(RunStatus $s): string => $s->value, RunStatus::cases()),
        );
    }
}
