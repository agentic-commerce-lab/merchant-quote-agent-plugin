<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;

final class QuoteServicingTriggerStateTest extends TestCase
{
    public function testItSubscribesToTwoCoreEventNames(): void
    {
        self::assertSame(
            ['state_machine.quote.state_changed', 'quote_comment.written'],
            array_keys(QuoteServicingTrigger::getSubscribedEvents()),
        );
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testEnteringOpenQueuesTheQuote(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        (new QuoteServicingTrigger($bus))->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent('open'));

        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0] ?? null;
        self::assertNotNull($message);
        self::assertSame('q1', $message->quoteId);
        self::assertSame('state_entered', $message->reason);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testEnteringChangeRequestedQueuesTheQuote(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        (new QuoteServicingTrigger($bus))->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'change_requested',
        ));

        self::assertCount(1, $bus->messages);
    }

    /**
     * `reopen` via `request_change` (replied -> reopen) is what a released
     * SwagCommercial (6.7.1.2-6.7.12) names the same event trunk calls
     * `change_requested` — a buyer asking for changes.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testEnteringReopenQueuesTheQuote(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        (new QuoteServicingTrigger($bus))->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'reopen',
            fromState: 'replied',
            transitionName: 'request_change',
        ));

        self::assertCount(1, $bus->messages);
    }

    /**
     * `reopen` via the `reopen` transition (declined -> reopen) is a MERCHANT
     * un-declining a previously declined quote, not a buyer asking anything.
     * Same destination state as the buyer's `request_change`, different actor
     * — only the transition name tells them apart, so this must queue nothing.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testMerchantDrivenReopenQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        (new QuoteServicingTrigger($bus))->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent(
            'reopen',
            fromState: 'declined',
            transitionName: 'reopen',
        ));

        self::assertSame([], $bus->messages);
    }

    /**
     * `in_review` and `replied` are the states the agent's OWN servicing drives.
     * Keeping them out of the trigger set means a self-trigger cannot happen
     * even if the context stamp were ever lost.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testEnteringAStateTheAgentItselfDrivesQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $trigger = new QuoteServicingTrigger($bus);

        foreach (['in_review', 'replied', 'accepted', 'draft'] as $state) {
            $trigger->onQuoteStateChanged(QuoteTriggerEventFixture::stateEvent($state));
        }

        self::assertSame([], $bus->messages);
    }

    /**
     * A transition LEAVING `open` reports `open` as its state name (the event
     * names the side it fired for, not the destination) — so this is the case
     * that actually exercises the enter-only filter. Naming the nextState
     * `open` too, as the transition into review from an accepted quote might,
     * would let the trigger-states check silently absorb a missing filter: the
     * leave side of draft -> open always reports `draft`, which never matches
     * TRIGGER_STATES regardless of this filter.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testTheLeaveSideOfATransitionQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::stateEvent(
            'in_review',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
            fromState: 'open',
        );

        (new QuoteServicingTrigger($bus))->onQuoteStateChanged($event);

        self::assertSame([], $bus->messages);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testAnAgentDrivenTransitionQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::stateEvent('open', context: AgentContext::create());

        (new QuoteServicingTrigger($bus))->onQuoteStateChanged($event);

        self::assertSame([], $bus->messages);
    }

    /**
     * A snapshot-lane transition is a mirror, never an independent buyer action,
     * and its re-versioned Context has lost every state — including ours. Without
     * the version filter this would queue the quote.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testATransitionOnTheSnapshotVersionQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::stateEvent('open', context: QuoteTriggerEventFixture::snapshotContext());

        (new QuoteServicingTrigger($bus))->onQuoteStateChanged($event);

        self::assertSame([], $bus->messages);
    }
}
