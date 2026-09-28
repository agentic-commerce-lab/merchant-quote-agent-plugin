<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bench;

use MerchantQuoteAgentPlugin\Tests\Bench\ScenarioAsk;
use PHPUnit\Framework\TestCase;

final class ScenarioAskTest extends TestCase
{
    public function testAPlaceholderRendersAsAFactorOfTheUnitPrice(): void
    {
        self::assertSame('Can you get to 71.20 a unit?', ScenarioAsk::render(
            'Can you get to {unit*0.89} a unit?',
            80.0,
        ));
    }

    public function testEveryPlaceholderInTheTextIsRendered(): void
    {
        self::assertSame('45.00 or 40.00', ScenarioAsk::render('{unit*0.9} or {unit*0.8}', 50.0));
    }

    public function testTextWithoutAPlaceholderIsUntouched(): void
    {
        self::assertSame('Could you do 5% off?', ScenarioAsk::render('Could you do 5% off?', 80.0));
    }
}
