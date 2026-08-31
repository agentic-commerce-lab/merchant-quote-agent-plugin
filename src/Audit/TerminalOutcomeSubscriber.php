<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The decision record says what the agent did; this says whether it worked.
 * #19 reserved `terminal_state` and `terminal_at` for exactly this and left
 * them unwritten so the pipeline stayed the single owner of a record's
 * insertion.
 *
 * Subscribes to the CORE event, like QuoteServicingTrigger: SwagCommercial
 * dispatches its own `state_enter.quote.state.*` events carrying a QuoteEntity,
 * but those classes are `@internal` there and ADR 0001 confines untyped
 * commercial access to the bridge's adapters.
 *
 * SwagCommercial's own expiry needs no special handling: UpdateQuoteExpireTaskHandler
 * drives ACTION_EXPIRE through StateMachineRegistry, so it fires this event
 * like any other transition.
 *
 * NO AgentContext::STATE guard, unlike QuoteServicingTrigger. The agent drives
 * exactly two transitions — `process` in OfferApplier and `sent` in
 * ReplyComposer — and neither is terminal, so this subscriber cannot hear its
 * own writes. A guard that can never fire would be worse than this comment.
 *
 * Known gap, accepted: `admin_cancel` is reachable from `in_review`, which is
 * where the agent's own pass sits. Cancel a quote mid-pass and this stamps the
 * record from the PREVIOUS pass, then NegotiationPipeline's `finally` inserts a
 * newer record carrying no outcome. It needs a merchant cancelling inside the
 * few seconds a pass runs and costs one unlabelled row. Closing it means
 * coordinating with the pipeline's record lifecycle, which recreates the
 * two-owners problem #33 was split out of #19 to avoid.
 */
final readonly class TerminalOutcomeSubscriber implements EventSubscriberInterface
{
    /**
     * The five states that end a negotiation. Only `accepted` is graph-terminal
     * — the other four have a `reopen` or `admin_resend` edge out — so this is
     * a business label, not a property the state machine guarantees.
     */
    private const TERMINAL_STATES = ['accepted', 'declined', 'expired', 'cancelled', 'withdrawn'];

    public function __construct(
        private TerminalOutcomeWriterInterface $writer,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return ['state_machine.quote.state_changed' => 'onQuoteStateChanged'];
    }

    public function onQuoteStateChanged(StateMachineStateChangeEvent $event): void
    {
        // Fires twice per transition, leave then enter. Only entering is news.
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        // A snapshot-lane transition is a mirror, never a merchant's decision.
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        if (!\in_array($event->getStateName(), self::TERMINAL_STATES, strict: true)) {
            return;
        }

        $quoteId = $event->getTransition()->getEntityId();

        try {
            $this->writer->recordTerminalOutcome($quoteId, $event->getStateName(), new \DateTimeImmutable());
        } catch (\Throwable $e) {
            // A merchant clicking accept must never see a 500 because an audit
            // write failed, and the expiry task must not abort its batch.
            $this->logger->error('The terminal quote outcome could not be recorded.', [
                'quoteId' => $quoteId,
                'terminalState' => $event->getStateName(),
                'exception' => $e,
            ]);
        }
    }
}
