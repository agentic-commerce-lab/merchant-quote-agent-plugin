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
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10), and the branch that crosses it
 * is real: `reopen` needs its own transition-name check to tell a buyer's
 * `request_change` from a merchant's `reopen` apart, on top of the state-name
 * and enter-side filters every other trigger state already needed. See
 * TRIGGER_STATES for why the two cannot be told apart by state name alone.
 */
final readonly class QuoteServicingTrigger implements EventSubscriberInterface
{
    /**
     * The only states that mean the agent has something to do. Notably NOT
     * `in_review` or `replied`: those are the states the agent's own servicing
     * drives, so leaving them out means a self-trigger is impossible by
     * construction, independently of the context stamp.
     *
     * `change_requested` is trunk's name for a buyer asking for changes. A
     * released SwagCommercial (6.7.1.2-6.7.12) has no `change_requested` state
     * at all - it reuses `reopen` for the same purpose, but `reopen` there has
     * TWO inbound transitions, not one:
     *
     *  - `request_change` (replied -> reopen): the buyer asking for changes.
     *    This is `change_requested` under a different name, and belongs here.
     *  - `reopen` (declined -> reopen): a MERCHANT un-declining a quote they
     *    previously declined. There is no new buyer input, so servicing this
     *    one would mean an agent reply lands because a merchant gave the
     *    customer a second chance, not because the customer asked anything -
     *    exactly the kind of surprise this plugin exists to prevent.
     *
     * State name alone cannot tell those two apart, so `reopen` stays listed
     * here unconditionally and onQuoteStateChanged() checks the transition
     * name too, but only for `reopen`.
     */
    private const TRIGGER_STATES = ['open', 'change_requested', 'reopen'];

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

        // `reopen` alone does not say who acted - see TRIGGER_STATES. Service
        // it only for the buyer's `request_change` transition, not for a
        // merchant's `reopen` transition un-declining the quote.
        if ($event->getStateName() === 'reopen' && $event->getTransition()->getTransitionName() !== 'request_change') {
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

            if (self::isMerchantComment($result->getPayload())) {
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

    /**
     * A comment the payload PROVES a merchant wrote: `createdById` set and
     * neither buyer column. SwagCommercial's QuoteActionController writes
     * exactly that shape — it passes customerId and employeeId as literal
     * nulls and QuoteCommenter fills createdById from the AdminApiSource —
     * while the storefront's QuoteCommentRoute, in store-api scope, can never
     * produce an admin user id at all.
     *
     * Positive identification only, and that is the point. This reads a WRITE
     * PAYLOAD, not the persisted row: a key that is simply absent must never
     * be read as "nobody wrote it", or a payload shape we have not seen would
     * silently drop a buyer's ask. Anything unrecognised still queues a pass,
     * and ServicingFingerprint stops it there — a merchant comment leaves the
     * fingerprint identical, so the pass returns before the preflight and
     * before any model call.
     *
     * So this filter is not the fix for #55; the fingerprint and the
     * conversation split are. It is worth having anyway: without it a
     * merchant's note takes the per-quote lock, writes the crash-budget
     * counter and logs a pass that did nothing.
     *
     * Deliberately a second expression of `QuoteComment::isAuthored() &&
     * !isBuyerAuthored()`, against the DAL write payload rather than the read
     * model — the DTO cannot express "this key is absent" the way this
     * predicate must. The two must move together if SwagCommercial's
     * authorship columns ever change; nothing enforces that but the two of
     * them being read together.
     *
     * Public for Audit\MerchantCommentResolutionSubscriber (QA-05), which
     * reads the same payload for the opposite purpose: there a comment this
     * proves is the merchant's resolves an escalation. Positive
     * identification is just as right there: an unrecognised shape costs a
     * missed resolution, never a buyer's ask read as the deal desk's answer.
     *
     * @param array<string, mixed> $payload
     */
    public static function isMerchantComment(array $payload): bool
    {
        $createdById = $payload['createdById'] ?? null;

        return (
            \is_string($createdById)
            && $createdById !== ''
            && ($payload['customerId'] ?? null) === null
            && ($payload['employeeId'] ?? null) === null
        );
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    private function queue(string $quoteId, ServicingTriggerReason $reason): void
    {
        // Never serviced in the triggering request: LLM latency is seconds.
        $this->bus->dispatch(ServiceQuoteMessage::because($quoteId, $reason));
    }
}
