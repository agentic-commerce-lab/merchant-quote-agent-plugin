<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use PHPUnit\Framework\Assert;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builders for the two Shopware events the servicing trigger listens to, plus a
 * collecting bus. Shared by both trigger test classes; follows the repo's
 * tests/Unit/Policy/*Fixture.php convention.
 */
final class QuoteTriggerEventFixture
{
    private function __construct() {}

    public static function stateEvent(
        string $nextState,
        string $side = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
        ?Context $context = null,
        string $fromState = 'draft',
    ): StateMachineStateChangeEvent {
        $machine = new StateMachineEntity();
        $machine->setId('0191bd7f7a5e7c9e8a3f4b2c1d0e9f88');
        $machine->setTechnicalName('quote.state');

        $from = new StateMachineStateEntity();
        $from->setId('0191bd7f7a5e7c9e8a3f4b2c1d0e9f01');
        $from->setTechnicalName($fromState);

        $to = new StateMachineStateEntity();
        $to->setId('0191bd7f7a5e7c9e8a3f4b2c1d0e9f02');
        $to->setTechnicalName($nextState);

        return new StateMachineStateChangeEvent(
            $context ?? Context::createDefaultContext(),
            $side,
            new Transition('quote', 'q1', 'customer_send', 'stateId'),
            $machine,
            $from,
            $to,
        );
    }

    public static function commentInsert(string $quoteId): EntityWriteResult
    {
        return new EntityWriteResult(
            'c-' . $quoteId,
            ['quoteId' => $quoteId, 'comment' => 'buyer ask'],
            'quote_comment',
            EntityWriteResult::OPERATION_INSERT,
        );
    }

    /** @param list<EntityWriteResult> $results */
    public static function commentEvent(array $results, ?Context $context = null): EntityWrittenEvent
    {
        return new EntityWrittenEvent('quote_comment', $results, $context ?? Context::createDefaultContext());
    }

    /**
     * A Context on the snapshot version lane. SwagCommercial mirrors comments
     * there via Context::createWithVersionId(), which drops every state — so a
     * mirrored event looks unstamped even for an agent write.
     */
    public static function snapshotContext(?Context $context = null): Context
    {
        return ($context ?? Context::createDefaultContext())->createWithVersionId('019cfaaf020219939ba2eea26ba651ae');
    }

    /** @return MessageBusInterface&object{messages: list<ServiceQuoteMessage>} */
    public static function collectingBus(): object
    {
        return new class implements MessageBusInterface {
            /** @var list<ServiceQuoteMessage> */
            public array $messages = [];

            /**
             * @param object|Envelope $message
             * @param array<array-key, \Symfony\Component\Messenger\Stamp\StampInterface> $stamps
             */
            #[\Override]
            public function dispatch($message, array $stamps = []): Envelope
            {
                // Assert::, not self:: — inside an anonymous class self:: is the
                // anonymous class, which has no assertion methods.
                Assert::assertInstanceOf(ServiceQuoteMessage::class, $message);
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
