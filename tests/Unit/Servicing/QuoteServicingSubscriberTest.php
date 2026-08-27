<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
    private LoggerInterface&MockObject $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->gateway = $this->createMock(QuoteGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
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

    public function testOnQuoteStateEnterDispatchesServiceMessage(): void
    {
        $events = QuoteServicingSubscriber::getSubscribedEvents();
        self::assertArrayHasKey('state_enter.quote.state.open', $events);
        self::assertArrayHasKey('state_enter.quote.state.in_review', $events);
        self::assertArrayHasKey('state_enter.quote.state.change_requested', $events);
        self::assertArrayHasKey('quote_comment.written', $events);

        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $snapshot = $this->createSnapshot('quote-123', 'open', $revision);

        $this->gateway->expects(self::once())->method('fetchSnapshot')->with('quote-123')->willReturn($snapshot);
        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn(ServiceQuoteMessage $msg): bool => (
                    $msg->quoteId === 'quote-123'
                    && Uuid::isValid($msg->messageId)
                    && $msg->salesChannelId === 'sales-channel-456'
                    && $msg->revision->versionId === '018b449b2ba170a4a589cf8cb59a35e4'
                ),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway, $this->logger);

        $event = $this->createStateChangeEvent('quote-123', Context::createDefaultContext());
        $subscriber->onQuoteStateEnter($event);
    }

    public function testOnQuoteStateEnterSkipsWhenContextHasAgentState(): void
    {
        $context = Context::createDefaultContext();
        $context->addState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING);

        $this->gateway->expects(self::never())->method('fetchSnapshot');
        $this->bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway, $this->logger);

        $event = $this->createStateChangeEvent('quote-123', $context);
        $subscriber->onQuoteStateEnter($event);
    }

    public function testOnQuoteStateEnterSkipsWhenGatewayIsNullOrQuoteIsMissing(): void
    {
        $this->bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($this->bus, null, $this->logger);
        $event = $this->createStateChangeEvent('quote-123', Context::createDefaultContext());
        $subscriber->onQuoteStateEnter($event);

        $this->gateway
            ->expects(self::once())
            ->method('fetchSnapshot')
            ->with('quote-123')
            ->willThrowException(QuoteNotFoundException::forId('quote-123'));
        $this->bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway, $this->logger);

        $event = $this->createStateChangeEvent('quote-123', Context::createDefaultContext());
        $subscriber->onQuoteStateEnter($event);
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
            MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT => 'A different agent note.',
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
            ->willReturnCallback(static function (ServiceQuoteMessage $message) use (&$dispatchedQuoteIds): Envelope {
                $dispatchedQuoteIds[] = $message->quoteId;

                return new Envelope($message);
            });

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway, $this->logger);

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

    public function testOnQuoteCommentWrittenDispatchesWhenCreatedByAuthorMatchesAgentStamp(): void
    {
        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $snapshot = $this->createSnapshot('quote-staff', 'in_review', $revision, customFields: [
            MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT => 'Shared comment text.',
        ]);

        $this->gateway->expects(self::once())->method('fetchSnapshot')->with('quote-staff')->willReturn($snapshot);
        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass()));

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway, $this->logger);
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

    public function testOnQuoteCommentWrittenSkipsLiveAgentContextAndMatchingPersistedStamp(): void
    {
        $context = Context::createDefaultContext();
        $context->addState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING);

        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $snapshot = $this->createSnapshot('quote-agent', 'in_review', $revision, customFields: [
            MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT => 'Agent offer details here.',
        ]);

        $this->gateway->expects(self::once())->method('fetchSnapshot')->with('quote-agent')->willReturn($snapshot);
        $this->bus->expects(self::never())->method('dispatch');

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway, $this->logger);

        $event = $this->createCommentWrittenEvent([
            [
                'quoteId' => 'quote-live-agent',
                'customerId' => 'customer-789',
                'employeeId' => null,
                'comment' => 'Can we get a discount?',
            ],
        ], $context);

        $subscriber->onQuoteCommentWritten($event);

        $event = $this->createCommentWrittenEvent([
            [
                'quoteId' => 'quote-agent',
                'customerId' => null,
                'employeeId' => null,
                'comment' => 'Agent offer details here.',
            ],
        ], Context::createDefaultContext());

        $subscriber->onQuoteCommentWritten($event);
    }
}
