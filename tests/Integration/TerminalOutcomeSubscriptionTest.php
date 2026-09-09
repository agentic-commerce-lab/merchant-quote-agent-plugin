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
 * Drives `open --process--> in_review --sent--> replied --decline--> declined`
 * rather than a single `admin_cancel` from `open`: `admin_cancel` and
 * `cancelled` are trunk-only (a released SwagCommercial's `open` state offers
 * only `process` and `sent` — verified live, "Illegal transition 'admin_cancel'
 * from state ... Possible transitions are: process, sent"). `process`, `sent`
 * and `decline` are each already exercised against this shop by TransitionTest
 * and the state graph documented in #33's design doc, and `declined` is one of
 * the five terminal states on both profiles, so this reaches a real terminal
 * transition without depending on a fixture quote already sitting in a
 * particular state.
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

        self::driveOpenQuoteToDeclined($quoteId, $context);

        $record = self::records()
            ->search(new Criteria([$recordId]), $context)
            ->first();
        self::assertInstanceOf(QuoteDecisionRecord::class, $record);
        self::assertSame(
            'declined',
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

        self::driveOpenQuoteToDeclined($quoteId, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));

        self::assertSame(0, self::records()->searchIds($criteria, $context)->getTotal());
    }

    /**
     * `open --process--> in_review --sent--> replied --decline--> declined`,
     * through the raw core registry rather than the bridge's
     * QuoteStateTransitioner: see the class docblock for why this path was
     * chosen over `admin_cancel`. The two intermediate transitions fire
     * `in_review`/`replied` mail flows exactly like TransitionTest's do, and
     * are harmless there for the same two reasons documented on that class.
     */
    private static function driveOpenQuoteToDeclined(string $quoteId, Context $context): void
    {
        $registry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $registry);

        foreach (['process', 'sent', 'decline'] as $action) {
            $registry->transition(new Transition('quote', $quoteId, $action, 'stateId'), $context);
        }
    }

    private static function records(): EntityRepository
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }
}
