<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use Override;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues an A2CN observation when a quote enters a buyer-visible offer state.
 *
 * Deliberately a STATE subscription and not a hook into the negotiation
 * pipeline: a human who edits a quote after an escalation and replies to the
 * buyer produces an offer too, and that offer must be signed like any other.
 * `QuoteServicingTrigger` excludes `replied` for the opposite reason — that is
 * the state its own servicing drives — so the two subscriptions do not overlap.
 *
 * Only the LIVE-version half of QuoteServicingTrigger::isNotOurBusiness() is
 * mirrored here, deliberately not the STATE half. That other half exists to
 * suppress the agent's own writes — but this subscriber's entire purpose is to
 * sign the offer the agent itself just produced, so filtering out the agent's
 * own context stamp would silence the one transition this class exists to
 * observe. A state change in a draft/mirror version is still filtered: it would
 * have us sign terms that are not the live quote's.
 */
final readonly class OfferVisibleStateSubscriber implements EventSubscriberInterface
{
    private const OBSERVED_STATES = ['replied'];

    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, string> */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return ['state_machine.quote.state_changed' => 'onQuoteStateChanged'];
    }

    public function onQuoteStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        // Fires twice per transition, leave then enter. Only entering is news.
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if (!\in_array($event->getStateName(), self::OBSERVED_STATES, strict: true)) {
            return;
        }

        $quoteId = $event->getTransition()->getEntityId();

        try {
            $this->bus->dispatch(new ObserveQuoteMessage($quoteId));
        } catch (\Throwable $error) {
            // The dispatch is a side effect of somebody else's transition. On
            // a shop whose MESSENGER_TRANSPORT_DSN points at a separate
            // broker, letting this out would make a merchant's admin reply
            // 500 and the agent's own pass fail against its crash budget —
            // for evidence. ObserveQuoteHandler states the same posture:
            // servicing parks its message, evidence has no such duty.
            $this->logger->error('A2CN observation could not be queued for a quote entering an offer state.', [
                'quoteId' => $quoteId,
                'exception' => $error,
            ]);
        }
    }
}
