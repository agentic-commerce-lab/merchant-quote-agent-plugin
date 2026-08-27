<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\QuoteServicingStates;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class QuoteServicingStatesTest extends TestCase
{
    #[TestWith(['open', true])]
    #[TestWith(['in_review', true])]
    #[TestWith(['change_requested', true])]
    #[TestWith(['replied', false])]
    #[TestWith(['cancelled', false])]
    #[TestWith(['', false])]
    public function testIdentifiesWhetherAQuoteStateIsServiceable(string $state, bool $expected): void
    {
        self::assertSame($expected, QuoteServicingStates::isServiceable($state));
    }
}
