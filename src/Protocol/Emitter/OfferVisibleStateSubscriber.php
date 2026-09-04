<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use Override;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues an A2CN observation when a quote enters a buyer-visible offer state.
 *
 * Deliberately a STATE subscription and not a hook into the negotiation
 * pipeline: a human who edits a quote after an escalation and replies to the
 * buyer produces an offer too, and that offer must be signed like any other.
 * `QuoteServicingTrigger` excludes `replied` for the opposite reason — that is
 * the state its own servicing drives — so the two subscriptions do not overlap.
 */
final readonly class OfferVisibleStateSubscriber implements EventSubscriberInterface
{
    private const OBSERVED_STATES = ['replied'];

    public function __construct(
        private MessageBusInterface $bus,
    ) {}

    /** @return array<string, string> */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return ['state_machine.quote.state_changed' => 'onQuoteStateChanged'];
    }

    /** @throws ExceptionInterface */
    public function onQuoteStateChanged(StateMachineStateChangeEvent $event): void
    {
        // Fires twice per transition, leave then enter. Only entering is news.
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if (!\in_array($event->getStateName(), self::OBSERVED_STATES, strict: true)) {
            return;
        }

        $this->bus->dispatch(new ObserveQuoteMessage($event->getTransition()->getEntityId()));
    }
}
