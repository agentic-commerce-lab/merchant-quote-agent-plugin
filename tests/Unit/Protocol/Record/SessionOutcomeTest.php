<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Record\SessionOutcome;
use PHPUnit\Framework\TestCase;

final class SessionOutcomeTest extends TestCase
{
    public function testItMapsQuoteStateToATerminalOutcome(): void
    {
        self::assertSame('REJECTED_FINAL', SessionOutcome::for('declined', expired: false));
        self::assertSame('TIMED_OUT', SessionOutcome::for('replied', expired: true));
    }

    public function testALiveSessionHasNoOutcome(): void
    {
        self::assertNull(SessionOutcome::for('replied', expired: false));
        self::assertNull(SessionOutcome::for('open', expired: false));
    }

    public function testDeclinedWinsOverExpiry(): void
    {
        self::assertSame('REJECTED_FINAL', SessionOutcome::for('declined', expired: true));
    }
}
