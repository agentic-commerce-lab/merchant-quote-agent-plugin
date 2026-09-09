<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * How long the deal desk took to answer an escalated quote.
 *
 * A sibling of TerminalOutcomeSubscriber on the same core event, and
 * deliberately not folded into it: that one reports the five states that END a
 * negotiation, this one reports EVERY state, because the transition that
 * resolves an escalation is usually `sent` — the merchant putting a revised
 * offer in front of the buyer — which is not terminal at all.
 *
 * QuoteEscalator does not transition the quote when it escalates. It writes a
 * customFields marker and a buyer-facing comment, and the human then acts in
 * SwagCommercial's own quote admin, so the resolution can only be observed
 * from the quote's state machine.
 *
 * No state filter, therefore, and no author filter either: the core event
 * carries no author. Any transition by anyone closes the escalation, including
 * a buyer withdrawing the quote. `resolvedState` is stored so that stays
 * inspectable. The only thing that would distinguish the deal desk from the
 * buyer is SwagCommercial's `quote_history`, which does not exist on 7.12.
 *
 * Which pass is stamped, and whether one is stamped at all, is
 * EscalationResolutionWriter's decision — it holds both guards, because it is
 * the thing that touches the row.
 *
 * AgentContext::STATE guard, UNLIKE TerminalOutcomeSubscriber: that one can
 * skip the check because neither transition the agent drives is terminal. This
 * one cannot, because DecisionRecorder::finish() inserts a pass's audit row at
 * the very END of the pass — after OfferApplier has already driven `process`
 * and after ReplyComposer has already driven `sent` (see both classes'
 * transition() calls, both made through AgentContext::create()). While a pass
 * is still running, the newest row on the quote is therefore still the
 * PREVIOUS pass's row. If that previous pass escalated, it is exactly the row
 * matching `outcome === 'escalated'` and `resolvedAt === null` —
 * EscalationResolutionWriter's guards cannot tell it apart from a genuine
 * human resolution, because it genuinely is one until this fires. Without this
 * guard the agent's own next pass would record itself as the human resolution,
 * reporting escalations "resolved" in minutes by the agent rather than by the
 * deal desk, which destroys the measure this subscriber exists to produce.
 *
 * Accepted cost of the guard: an agent-driven `sent` that genuinely concluded
 * a human's escalation goes unrecorded. That is fine — a pass reaching
 * ReplyComposer answered the buyer itself, which is the agent auto-executing
 * rather than the deal desk resolving, and the quote leaves the review queue
 * through the disposition path (TerminalOutcomeSubscriber /
 * QuoteDecisionRecord's own outcome) regardless of whether this measure
 * catches it.
 */
final readonly class EscalationResolutionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EscalationResolutionWriterInterface $writer,
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

        // The agent's own mid-pass transition — see the class docblock for why
        // this guard exists here but not on TerminalOutcomeSubscriber.
        if ($event->getContext()->hasState(AgentContext::STATE)) {
            return;
        }

        $quoteId = $event->getTransition()->getEntityId();

        // Same two-layer guard as TerminalOutcomeSubscriber, for the same
        // reason: a merchant clicking "send offer" must never see a 500
        // because an audit write failed, and a throwing logger must not fail
        // the transition either.
        try {
            try {
                $this->writer->recordEscalationResolution($quoteId, $event->getStateName(), new \DateTimeImmutable());
            } catch (\Throwable $e) {
                $this->logger->error('The escalation resolution could not be recorded.', [
                    'quoteId' => $quoteId,
                    'resolvedState' => $event->getStateName(),
                    'exception' => $e,
                ]);
            }
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty: a logger that throws must not fail the
            // quote's state transition, and there is nowhere left to report
            // the failure.
        }
    }
}
