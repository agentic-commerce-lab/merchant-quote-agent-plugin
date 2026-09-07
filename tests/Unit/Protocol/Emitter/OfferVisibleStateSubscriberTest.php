<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Emitter\ObserveQuoteMessage;
use MerchantQuoteAgentPlugin\Protocol\Emitter\OfferVisibleStateSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Stringable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
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
        (new OfferVisibleStateSubscriber($bus, new NullLogger()))->onQuoteStateChanged(self::event(
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
        $subscriber = new OfferVisibleStateSubscriber($bus, new NullLogger());

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
     * A state change in a non-live (draft/mirror) version must not be signed
     * into the chain — it is not the live quote's terms.
     */
    public function testItIgnoresATransitionOutsideTheLiveVersion(): void
    {
        $bus = self::bus();
        $subscriber = new OfferVisibleStateSubscriber($bus, new NullLogger());

        $subscriber->onQuoteStateChanged(self::event(
            'replied',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            Uuid::randomHex(),
        ));

        self::assertSame([], $bus->messages);
    }

    /**
     * A broker outage must not fail the transition. On a shop whose
     * MESSENGER_TRANSPORT_DSN points at a separate broker, an unguarded
     * dispatch means a merchant's admin reply 500s and the agent's own pass
     * fails against its crash budget — for an evidence side effect.
     * QuoteServicingTrigger has the same shape but deliberately excludes
     * `replied`, so this exposure is only here; ObserveQuoteHandler's own
     * docblock states the posture ("Servicing parks its message… evidence has
     * no such duty").
     */
    public function testABrokerOutageDoesNotFailTheTransition(): void
    {
        $logger = self::recordingLogger();
        $subscriber = new OfferVisibleStateSubscriber(self::failingBus(), $logger);

        $subscriber->onQuoteStateChanged(self::event(
            'replied',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
        ));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('quote-1', $logger->records[0]['context']['quoteId'] ?? null);
        self::assertInstanceOf(\Throwable::class, $logger->records[0]['context']['exception'] ?? null);
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
    private static function event(string $state, string $side, ?string $versionId = null): StateMachineStateChangeEvent
    {
        $transition = new Transition('quote', 'quote-1', 'reply', 'stateId');

        $stateMachine = new StateMachineEntity();
        $stateMachine->setId('state-machine-1');
        $stateMachine->setTechnicalName('quote.state');

        $stateEntity = new StateMachineStateEntity();
        $stateEntity->setId('state-1');
        $stateEntity->setTechnicalName($state);

        return new StateMachineStateChangeEvent(
            new Context(new SystemSource(), versionId: $versionId ?? Defaults::LIVE_VERSION),
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

    /** A bus that cannot reach its broker, which is a TransportException in production. */
    private static function failingBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new TransportException('the broker is unreachable');
            }
        };
    }

    /** @return AbstractLogger&object{records: list<array{level: string, context: array<array-key, mixed>}>} */
    private static function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<array-key, mixed>}> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param array<array-key, mixed> $context
             */
            #[\Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };
    }
}
