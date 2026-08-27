<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use PHPUnit\Framework\TestCase;

final class QuoteRevisionTest extends TestCase
{
    public function testIdenticalInstantsMatch(): void
    {
        $updatedAt = new \DateTimeImmutable('2026-08-27 10:00:00.100');

        $a = new QuoteRevision('v1', $updatedAt);
        $b = new QuoteRevision('v1', $updatedAt);

        self::assertTrue($a->matches($b));
    }

    public function testSameSecondDifferentMillisecondsDoNotMatch(): void
    {
        $a = new QuoteRevision('v1', new \DateTimeImmutable('2026-08-27 10:00:00.100'));
        $b = new QuoteRevision('v1', new \DateTimeImmutable('2026-08-27 10:00:00.400'));

        self::assertFalse($a->matches($b));
    }
}
