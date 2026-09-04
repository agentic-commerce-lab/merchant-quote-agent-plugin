<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteMessage;
use MerchantQuoteAgentPlugin\Protocol\Emitter\OfferVisibleStateSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class OfferVisibleStateSubscriberTest extends TestCase
{
    public function testItSubscribesToTheCoreQuoteStateEvent(): void
    {
        self::assertArrayHasKey(
            'state_machine.quote.state_changed',
            OfferVisibleStateSubscriber::getSubscribedEvents(),
        );
    }

    public function testItQueuesAnObservationWhenAQuoteEntersReplied(): void
    {
        $bus = self::bus();
        (new OfferVisibleStateSubscriber($bus))->onQuoteStateChanged(self::event(
            'replied',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
        ));

        self::assertCount(1, $bus->messages);
        self::assertInstanceOf(ObserveQuoteMessage::class, $bus->messages[0]);
        self::assertSame('quote-1', $bus->messages[0]->quoteId);
    }

    public function testItIgnoresTheLeaveSideAndOtherStates(): void
    {
        $bus = self::bus();
        $subscriber = new OfferVisibleStateSubscriber($bus);

        $subscriber->onQuoteStateChanged(self::event(
            'replied',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
        ));
        $subscriber->onQuoteStateChanged(self::event(
            'open',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
        ));

        self::assertSame([], $bus->messages);
    }

    /**
     * The real StateMachineStateChangeEvent constructor (verified against
     * vendor/shopware/core) takes a Transition value object plus a
     * StateMachineEntity and two StateMachineStateEntity instances — not a
     * StateMachineTransitionEntity, which is a DAL row for a different
     * purpose. getStateName() reads the next state's technical name on ENTER
     * and the previous state's on LEAVE, so the fixture sets both to the same
     * name and lets the side under test pick the one that matters.
     */
    private static function event(string $state, string $side): StateMachineStateChangeEvent
    {
        $transition = new Transition('quote', 'quote-1', 'reply', 'stateId');

        $stateMachine = new StateMachineEntity();
        $stateMachine->setId('state-machine-1');
        $stateMachine->setTechnicalName('quote.state');

        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setId('state-1');
        $stateEntity->setTechnicalName($state);

        return new StateMachineStateChangeEvent(
            Context::createDefaultContext(),
            $side,
            $transition,
            $stateMachine,
            $stateEntity,
            $stateEntity,
        );
    }

    /** @return MessageBusInterface&object{messages: list<object>} */
    private static function bus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
