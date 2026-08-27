<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class ServicingSubscriberTest extends IntegrationTestCase
{
    public function testShopwareMessengerParksAfterThreeTransportRetries(): void
    {
        $failedTransport = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(TransportInterface::class, $failedTransport);
        self::assertSame(
            $failedTransport,
            static::getContainer()->get('messenger.failure_transports.default'),
            'Shopware does not use the failed transport as its default failure transport.',
        );

        $retryStrategies = static::getContainer()->get('messenger.retry_strategy_locator');
        self::assertInstanceOf(ServiceLocator::class, $retryStrategies);
        $asyncRetryStrategy = $retryStrategies->get('async');
        self::assertInstanceOf(RetryStrategyInterface::class, $asyncRetryStrategy);

        self::assertTrue($asyncRetryStrategy->isRetryable(new Envelope(new \stdClass(), [new RedeliveryStamp(2)])));
        self::assertFalse($asyncRetryStrategy->isRetryable(new Envelope(new \stdClass(), [new RedeliveryStamp(3)])));
    }

    public function testInstalledQuoteRequestedSubscriberRoutesToTheAsyncTransport(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $snapshot = $gateway->fetchSnapshot($quoteId);

        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $highestMessageId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM messenger_messages');

        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $event = new class($quoteId, Context::createDefaultContext()) {
            public function __construct(
                private readonly string $quoteId,
                private readonly Context $context,
            ) {}

            public function getQuoteId(): string
            {
                return $this->quoteId;
            }

            public function getContext(): Context
            {
                return $this->context;
            }
        };

        $dispatcher->dispatch($event, 'quote.requested');

        /** @var list<array{queue_name: string, body: string, headers: string}> $messages */
        $messages = $connection->fetchAllAssociative('SELECT queue_name, body, headers FROM messenger_messages WHERE id > :id AND body LIKE :quoteId', [
            'id' => $highestMessageId,
            'quoteId' => '%' . $quoteId . '%',
        ]);

        self::assertCount(1, $messages, 'quote.requested did not produce exactly one asynchronous service message.');
        // Shopware names the transport `async`; its default Doctrine DSN stores
        // that transport in the `default` queue (low-priority/failed use their
        // own explicit queue_name query parameters).
        self::assertSame('default', $messages[0]['queue_name']);
        self::assertStringContainsString(
            str_replace(search: '\\', replace: '\\\\', subject: ServiceQuoteMessage::class),
            $messages[0]['headers'],
        );
        self::assertStringContainsString($snapshot->identity->salesChannelId, $messages[0]['body']);
    }

    public function testStateTransitionDispatchesServiceQuoteMessage(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $snapshot = $gateway->fetchSnapshot($quoteId);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $subscriber = new QuoteServicingSubscriber($bus, $gateway);

        // Transition quote: open --process--> in_review
        $gateway->transition($quoteId, QuoteTransition::Process);

        $afterSnapshot = $gateway->fetchSnapshot($quoteId);
        self::assertSame('in_review', $afterSnapshot->lifecycle->stateTechnicalName);

        $subscriber->onQuoteStateEnter(self::stateTransitionEvent($quoteId, Context::createDefaultContext()));

        self::assertCount(1, $dispatched);
        /** @var ServiceQuoteMessage $msg */
        $msg = $dispatched[0];
        self::assertInstanceOf(ServiceQuoteMessage::class, $msg);
        self::assertTrue(Uuid::isValid($msg->messageId));
        self::assertSame($quoteId, $msg->quoteId);
        self::assertSame($snapshot->identity->salesChannelId, $msg->salesChannelId);
        self::assertTrue($msg->revision->matches($afterSnapshot->revision));
    }

    public function testAgentContextStateSuppressesDispatch(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($bus, $gateway);

        $context = Context::createDefaultContext();
        $context->addState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING);

        $subscriber->onQuoteStateEnter(self::stateTransitionEvent($quoteId, $context));
    }

    public function testAgentCommentDiscriminatorSuppressesDispatch(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'in_review');
        $commentText = 'Agent stamped offer note ' . Uuid::randomHex();

        // Stamp quote customFields with the agent comment text
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT => $commentText,
        ]));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($bus, $gateway);

        $writeEvent = new EntityWrittenEvent(
            'quote_comment',
            [
                new EntityWriteResult(
                    Uuid::randomHex(),
                    [
                        'quoteId' => $quoteId,
                        'comment' => $commentText,
                        'customerId' => null,
                        'employeeId' => null,
                    ],
                    'quote_comment',
                    EntityWriteResult::OPERATION_INSERT,
                ),
            ],
            Context::createDefaultContext(),
        );

        $subscriber->onQuoteCommentWritten($writeEvent);
    }

    public function testDuplicateEligibleCommentRowsDispatchOneMessageForTheQuote(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'in_review');
        $snapshot = $gateway->fetchSnapshot($quoteId);

        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (ServiceQuoteMessage $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });
        $subscriber = new QuoteServicingSubscriber($bus, $gateway);
        $event = new EntityWrittenEvent(
            'quote_comment',
            [
                self::buyerCommentResult($quoteId, 'Can you sharpen the price?'),
                self::buyerCommentResult($quoteId, 'What is the delivery window?'),
            ],
            Context::createDefaultContext(),
        );

        $subscriber->onQuoteCommentWritten($event);

        self::assertCount(1, $dispatched);
        self::assertTrue(Uuid::isValid($dispatched[0]->messageId));
        self::assertSame($quoteId, $dispatched[0]->quoteId);
        self::assertSame($snapshot->identity->salesChannelId, $dispatched[0]->salesChannelId);
        self::assertTrue($snapshot->revision->matches($dispatched[0]->revision));
    }

    private static function buyerCommentResult(string $quoteId, string $comment): EntityWriteResult
    {
        return new EntityWriteResult(
            Uuid::randomHex(),
            [
                'quoteId' => $quoteId,
                'comment' => $comment,
                'customerId' => Uuid::randomHex(),
                'employeeId' => null,
                'createdById' => null,
            ],
            'quote_comment',
            EntityWriteResult::OPERATION_INSERT,
        );
    }

    private static function stateTransitionEvent(string $quoteId, Context $context): object
    {
        return new class(new Transition('quote', $quoteId, 'process', 'state-id'), $context) {
            public function __construct(
                private readonly Transition $transition,
                private readonly Context $context,
            ) {}

            public function getTransition(): Transition
            {
                return $this->transition;
            }

            public function getContext(): Context
            {
                return $this->context;
            }
        };
    }
}
