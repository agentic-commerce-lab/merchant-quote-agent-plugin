<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Normalises the two things that mean "this quote needs servicing" into one
 * message. Replaces the webhook path outright: nothing to verify per Shopware
 * build, no Flow Builder fallback to document.
 *
 * Both subscriptions are on CORE classes and core event names. SwagCommercial
 * dispatches its own `state_enter.quote.state.*` events carrying a QuoteEntity,
 * but those classes are `@internal` there, and ADR 0001 confines untyped
 * commercial access to the bridge's adapters — the core state-change event
 * carries everything needed, so there is no reason to widen that surface.
 *
 * `quote.requested` needs no separate subscription: a buyer's request runs
 * through the `customer_send` transition (draft → open), so the state
 * subscription already covers it. Verified against the shop's own
 * state_machine_transition table.
 */
final readonly class QuoteServicingTrigger implements EventSubscriberInterface
{
    /**
     * The only two states that mean the agent has something to do. Notably NOT
     * `in_review` or `replied`: those are the states the agent's own servicing
     * drives, so leaving them out means a self-trigger is impossible by
     * construction, independently of the context stamp.
     */
    private const TRIGGER_STATES = ['open', 'change_requested'];

    public function __construct(
        private MessageBusInterface $bus,
    ) {}

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // Dispatched by core's StateMachineRegistry as
            // 'state_machine.' . $machine->getTechnicalName() . '_changed',
            // and the quote machine's technical name is 'quote.state'.
            'state_machine.quote.state_changed' => 'onQuoteStateChanged',
            'quote_comment.written' => 'onQuoteCommentWritten',
        ];
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function onQuoteStateChanged(StateMachineStateChangeEvent $event): void
    {
        if (self::isNotOurBusiness($event->getContext())) {
            return;
        }

        // Fires twice per transition, leave then enter. Only entering a state
        // is news.
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if (!\in_array($event->getStateName(), self::TRIGGER_STATES, strict: true)) {
            return;
        }

        $this->queue($event->getTransition()->getEntityId(), ServicingTriggerReason::StateEntered);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function onQuoteCommentWritten(EntityWrittenEvent $event): void
    {
        if (self::isNotOurBusiness($event->getContext())) {
            return;
        }

        /** @var array<string, true> $queued */
        $queued = [];

        foreach ($event->getWriteResults() as $result) {
            // An edited comment is not a new ask.
            if ($result->getOperation() !== EntityWriteResult::OPERATION_INSERT) {
                continue;
            }

            $quoteId = $result->getPayload()['quoteId'] ?? null;

            if (!\is_string($quoteId) || \array_key_exists($quoteId, $queued)) {
                continue;
            }

            $queued[$quoteId] = true;
            $this->queue($quoteId, ServicingTriggerReason::CommentWritten);
        }
    }

    /**
     * Two filters, and the order does not matter but both are load-bearing.
     *
     * The VERSION filter is not an optimisation. `Context::createWithVersionId()`
     * builds a fresh Context re-applying only `scope` and `extensions`
     * (Framework/Context.php:173) — `states` is not among them. SwagCommercial's
     * QuoteHistoryWriter uses exactly that call to mirror every comment into the
     * quote's snapshot lane, so ONE addComment() fires TWO
     * `quote_comment.written` events: the live insert, which carries our stamp,
     * and the mirror, which has lost it. Measured, not inferred. Without this
     * filter the agent re-triggers on its own writes, and a buyer's single
     * comment queues two messages. A snapshot-lane write is by construction a
     * mirror of a live write, never an independent buyer action, so filtering to
     * the live lane is correct on its own merits.
     *
     * The STATE filter is what then suppresses the agent's own LIVE write, which
     * the version filter cannot see.
     */
    private static function isNotOurBusiness(Context $context): bool
    {
        return $context->getVersionId() !== Defaults::LIVE_VERSION || $context->hasState(AgentContext::STATE);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    private function queue(string $quoteId, ServicingTriggerReason $reason): void
    {
        // Never serviced in the triggering request: LLM latency is seconds.
        $this->bus->dispatch(ServiceQuoteMessage::because($quoteId, $reason));
    }
}
