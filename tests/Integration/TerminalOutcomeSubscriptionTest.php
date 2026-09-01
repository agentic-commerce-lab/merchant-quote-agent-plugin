<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * The subscriber end to end, against the real dispatcher and a real quote.
 *
 * Deliberately does NOT hand-register the subscriber the way
 * ServicingTriggerTest::withTrigger() does. The whole point of this test is
 * that services.php wires it: #39 records a feature that passed every unit
 * test, compiled cleanly and did nothing, because nothing executed it.
 *
 * `admin_cancel` from `open` rather than `accept` from `replied`: the seeded
 * shop has open quotes with line items and no suitable replied one, and
 * `cancelled` is one of the five terminal states either way.
 */
final class TerminalOutcomeSubscriptionTest extends IntegrationTestCase
{
    public function testARealTerminalTransitionStampsTheRecord(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), $context, 'open');
        $recordId = Uuid::randomHex();

        self::records()
            ->create([[
                'id' => $recordId,
                'quoteId' => $quoteId,
                'outcome' => 'offered',
            ]], $context);

        $registry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $registry);

        $registry->transition(new Transition('quote', $quoteId, 'admin_cancel', 'stateId'), $context);

        $record = self::records()
            ->search(new Criteria([$recordId]), $context)
            ->first();
        self::assertInstanceOf(QuoteDecisionRecord::class, $record);
        self::assertSame(
            'cancelled',
            $record->terminalState,
            'A real terminal transition stamped nothing: TerminalOutcomeSubscriber is not registered, '
            . 'or the core event name does not match.',
        );
        self::assertNotNull($record->terminalAt);
    }

    public function testATerminalTransitionOnAnUnservicedQuoteWritesNothing(): void
    {
        // The subscriber fires, finds no record, and must neither throw nor
        // invent a row. The transition itself has to succeed.
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), $context, 'open');

        $registry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $registry);

        $registry->transition(new Transition('quote', $quoteId, 'admin_cancel', 'stateId'), $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));

        self::assertSame(0, self::records()->searchIds($criteria, $context)->getTotal());
    }

    private static function records(): EntityRepository
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }
}
