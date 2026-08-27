<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class QuoteServicingSubscriberTest extends TestCase
{
    private MessageBusInterface&MockObject $bus;
    private QuoteGatewayInterface&MockObject $gateway;

    #[\Override]
    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->gateway = $this->createMock(QuoteGatewayInterface::class);
    }

    /** @param array<string, mixed> $customFields */
    private function createSnapshot(
        string $quoteId,
        string $state,
        QuoteRevision $revision,
        array $customFields = [],
    ): QuoteSnapshot {
        return new QuoteSnapshot(
            identity: new QuoteIdentity($quoteId, 'quote-num-1', 'EUR', 'sales-channel-456'),
            totals: new QuoteTotals(500.0, null),
            lifecycle: new QuoteLifecycle($state, null, $customFields),
            content: new QuoteContent([], [], null),
            revision: $revision,
        );
    }

    private function createStateChangeEvent(string $quoteId, Context $context): StateMachineStateChangeEvent
    {
        $transition = new Transition('quote', $quoteId, 'process', 'stateId');

        $stateMachine = new StateMachineEntity();
        $stateMachine->setTechnicalName('quote.state');

        $previousState = new StateMachineStateEntity();
        $previousState->setTechnicalName('open');

        $nextState = new StateMachineStateEntity();
        $nextState->setTechnicalName('in_review');

        return new StateMachineStateChangeEvent(
            $context,
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            $transition,
            $stateMachine,
            $previousState,
            $nextState,
        );
    }

    /** @param list<array<string, mixed>> $payloads */
    private function createCommentWrittenEvent(array $payloads, Context $context): EntityWrittenEvent
    {
        $writeResults = [];
        foreach ($payloads as $i => $payload) {
            $writeResults[] = new EntityWriteResult(
                'comment-' . $i,
                $payload,
                'quote_comment',
                EntityWriteResult::OPERATION_INSERT,
            );
        }

        return new EntityWrittenEvent('quote_comment', $writeResults, $context);
    }

    private static function isExpectedMessage(
        ServiceQuoteMessage $message,
        string $quoteId,
        QuoteRevision $revision,
    ): bool {
        self::assertTrue(Uuid::isValid($message->messageId));
        self::assertSame($quoteId, $message->quoteId);
        self::assertSame('sales-channel-456', $message->salesChannelId);
        self::assertSame($revision, $message->revision);

        return true;
    }

    public function testStateAndRequestedEventsSubscribeAndDispatchServiceMessages(): void
    {
        self::assertSame(
            [
                'quote.requested' => 'onQuoteStateEnter',
                'state_enter.quote.state.open' => 'onQuoteStateEnter',
                'state_enter.quote.state.in_review' => 'onQuoteStateEnter',
                'state_enter.quote.state.change_requested' => 'onQuoteStateEnter',
                'quote_comment.written' => 'onQuoteCommentWritten',
            ],
            QuoteServicingSubscriber::getSubscribedEvents(),
        );

        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $stateSnapshot = $this->createSnapshot('quote-123', 'open', $revision);
        $requestedSnapshot = $this->createSnapshot('quote-requested', 'open', $revision);

        $this->gateway
            ->expects(self::exactly(2))
            ->method('fetchSnapshot')
            ->willReturnCallback(static fn(string $quoteId): QuoteSnapshot => match ($quoteId) {
                'quote-123' => $stateSnapshot,
                'quote-requested' => $requestedSnapshot,
            });

        $dispatchedQuoteIds = [];
        $this->bus
            ->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (ServiceQuoteMessage $message) use (
                &$dispatchedQuoteIds,
                $revision,
            ): Envelope {
                self::isExpectedMessage($message, $message->quoteId, $revision);
                $dispatchedQuoteIds[] = $message->quoteId;

                return new Envelope($message);
            });

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);

        $context = Context::createDefaultContext();
        $requestedEvent = new class('quote-requested', $context) {
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

        $subscriber->onQuoteStateEnter($this->createStateChangeEvent('quote-123', $context));
        $subscriber->onQuoteStateEnter($requestedEvent);

        self::assertSame(['quote-123', 'quote-requested'], $dispatchedQuoteIds);
    }

    public function testOnQuoteStateEnterSkipsAgentContextNullGatewayAndMissingQuote(): void
    {
        $context = Context::createDefaultContext();
        $context->addState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING);

        $this->gateway
            ->expects(self::once())
            ->method('fetchSnapshot')
            ->with('quote-123')
            ->willThrowException(QuoteNotFoundException::forId('quote-123'));
        $this->bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);
        $subscriber->onQuoteStateEnter($this->createStateChangeEvent('quote-123', $context));

        $subscriber = new QuoteServicingSubscriber($this->bus, null);
        $subscriber->onQuoteStateEnter($this->createStateChangeEvent('quote-123', Context::createDefaultContext()));

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);
        $subscriber->onQuoteStateEnter($this->createStateChangeEvent('quote-123', Context::createDefaultContext()));
    }

    public function testOnQuoteCommentWrittenDispatchesForBuyerStaffAndUnmatchedAuthorlessComments(): void
    {
        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $buyerSnapshot = $this->createSnapshot('quote-buyer', 'in_review', $revision);
        $staffSnapshot = $this->createSnapshot('quote-staff', 'in_review', $revision);
        $authorlessSnapshot = $this->createSnapshot('quote-authorless', 'in_review', $revision, customFields: [
            MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID => 'comment-from-an-earlier-agent-write',
        ]);

        $this->gateway
            ->expects(self::exactly(3))
            ->method('fetchSnapshot')
            ->willReturnCallback(static fn(string $quoteId): QuoteSnapshot => match ($quoteId) {
                'quote-buyer' => $buyerSnapshot,
                'quote-staff' => $staffSnapshot,
                'quote-authorless' => $authorlessSnapshot,
            });

        $dispatchedQuoteIds = [];
        $this->bus
            ->expects(self::exactly(3))
            ->method('dispatch')
            ->willReturnCallback(static function (ServiceQuoteMessage $message) use (
                &$dispatchedQuoteIds,
                $revision,
            ): Envelope {
                self::isExpectedMessage($message, $message->quoteId, $revision);
                $dispatchedQuoteIds[] = $message->quoteId;

                return new Envelope($message);
            });

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);

        $event = $this->createCommentWrittenEvent([
            [
                'quoteId' => 'quote-buyer',
                'customerId' => 'customer-789',
                'employeeId' => null,
                'comment' => 'Can we get a volume discount?',
            ],
            [
                'quoteId' => 'quote-staff',
                'customerId' => null,
                'employeeId' => 'employee-789',
                'comment' => 'Staff follow-up for this quote.',
            ],
            [
                'quoteId' => 'quote-authorless',
                'customerId' => null,
                'employeeId' => null,
                'comment' => 'Buyer note without an explicit customerId',
            ],
        ], Context::createDefaultContext());

        $subscriber->onQuoteCommentWritten($event);

        self::assertSame(['quote-buyer', 'quote-staff', 'quote-authorless'], $dispatchedQuoteIds);
    }

    public function testOnQuoteCommentWrittenDispatchesAuthoredCommentEvenWhenItsIdMatchesAgentMarker(): void
    {
        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $snapshot = $this->createSnapshot('quote-staff', 'in_review', $revision, customFields: [
            MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID => 'comment-0',
        ]);

        $this->gateway->expects(self::once())->method('fetchSnapshot')->with('quote-staff')->willReturn($snapshot);
        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn(ServiceQuoteMessage $message): bool => self::isExpectedMessage(
                    $message,
                    'quote-staff',
                    $revision,
                ),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);
        $event = $this->createCommentWrittenEvent([
            [
                'quoteId' => 'quote-staff',
                'createdById' => 'admin-user-123',
                'customerId' => null,
                'employeeId' => null,
                'comment' => 'Shared comment text.',
            ],
        ], Context::createDefaultContext());

        $subscriber->onQuoteCommentWritten($event);
    }

    public function testOnQuoteCommentWrittenPromotesLiveAgentCommentIdWithoutDispatching(): void
    {
        $context = Context::createDefaultContext();
        $context->addState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING);

        $this->gateway->expects(self::never())->method('fetchSnapshot');
        $this->gateway
            ->expects(self::once())
            ->method('updateQuote')
            ->with('quote-live-agent', self::callback(static function (QuoteUpdate $update): bool {
                self::assertSame(['quote_agent_last_comment_id' => 'comment-0'], $update->customFields);

                return true;
            }));
        $this->bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);

        $event = $this->createCommentWrittenEvent([
            [
                'quoteId' => 'quote-live-agent',
                'customerId' => 'customer-789',
                'employeeId' => null,
                'comment' => 'Can we get a discount?',
            ],
        ], $context);

        $subscriber->onQuoteCommentWritten($event);
    }
}
