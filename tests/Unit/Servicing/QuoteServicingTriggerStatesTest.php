<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use PHPUnit\Framework\TestCase;

/**
 * The trigger states are a private constant, so this reads them reflectively
 * rather than driving a state-change event: the assertion is about which state
 * names the plugin recognises across SwagCommercial versions, and that is
 * exactly what the constant is.
 *
 * `in_review` and `replied` must stay out — those are the states the agent's own
 * servicing drives, and their absence is what makes a self-trigger impossible by
 * construction.
 */
final class QuoteServicingTriggerStatesTest extends TestCase
{
    public function testBothRenegotiationStateNamesTrigger(): void
    {
        $states = self::triggerStates();

        self::assertContains('open', $states);
        self::assertContains('change_requested', $states, 'trunk names it this');
        self::assertContains('reopen', $states, 'released SwagCommercial names it this');
    }

    public function testTheAgentsOwnStatesNeverTrigger(): void
    {
        $states = self::triggerStates();

        self::assertNotContains('in_review', $states);
        self::assertNotContains('replied', $states);
    }

    /** @return list<string> */
    private static function triggerStates(): array
    {
        /** @var list<string> $states */
        $states = (new \ReflectionClass(QuoteServicingTrigger::class))->getConstant('TRIGGER_STATES');

        return $states;
    }
}
