<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use PHPUnit\Framework\TestCase;

final class CommercialAvailabilityTest extends TestCase
{
    public function testReportsUnavailableWhenSwagCommercialIsAbsent(): void
    {
        // This suite runs without SwagCommercial on purpose: the plugin must
        // install and run on a shop that does not have it.
        self::assertFalse(CommercialAvailability::isAvailableByClass());
    }

    public function testIsLicensedNeverThrowsWhenSwagCommercialIsAbsent(): void
    {
        self::assertFalse(CommercialAvailability::isLicensed());
    }
}
