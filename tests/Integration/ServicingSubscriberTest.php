<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingSubscriber;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ServicingSubscriberTest extends IntegrationTestCase
{
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

        // State event object with getQuoteId() and getContext()
        $event = new class($quoteId, Context::createDefaultContext()) {
            public function __construct(
                private string $quoteId,
                private Context $context,
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

        $subscriber->onQuoteStateEnter($event);

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

        $event = new class($quoteId, $context) {
            public function __construct(
                private string $quoteId,
                private Context $context,
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

        $subscriber->onQuoteStateEnter($event);
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
}
