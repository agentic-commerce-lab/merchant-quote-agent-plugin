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
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class QuoteCommentProvenanceTest extends TestCase
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
    private function createSnapshot(QuoteRevision $revision, array $customFields): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('quote-agent', 'quote-num-1', 'EUR', 'sales-channel-456'),
            totals: new QuoteTotals(500.0, null),
            lifecycle: new QuoteLifecycle('in_review', null, $customFields),
            content: new QuoteContent([], [], null),
            revision: $revision,
        );
    }

    /** @param array<string, mixed> $payload */
    private function createCommentWrittenEvent(
        array|string $commentId,
        array $payload,
        Context $context,
    ): EntityWrittenEvent {
        return new EntityWrittenEvent(
            'quote_comment',
            [
                new EntityWriteResult($commentId, $payload, 'quote_comment', EntityWriteResult::OPERATION_INSERT),
            ],
            $context,
        );
    }

    private static function isExpectedMessage(ServiceQuoteMessage $message, QuoteRevision $revision): bool
    {
        self::assertTrue(Uuid::isValid($message->messageId));
        self::assertSame('quote-agent', $message->quoteId);
        self::assertSame('sales-channel-456', $message->salesChannelId);
        self::assertSame($revision, $message->revision);

        return true;
    }

    public function testIgnoresInvalidLiveAgentCommentIdentity(): void
    {
        $context = Context::createDefaultContext();
        $context->addState(MerchantQuoteAgentPlugin::CONTEXT_STATE_AGENT_SERVICING);

        $this->gateway->expects(self::never())->method('fetchSnapshot');
        $this->gateway->expects(self::never())->method('updateQuote');
        $this->bus->expects(self::never())->method('dispatch');

        $event = new EntityWrittenEvent(
            'quote_comment',
            [
                new EntityWriteResult(
                    '',
                    ['quoteId' => 'quote-agent'],
                    'quote_comment',
                    EntityWriteResult::OPERATION_INSERT,
                ),
                new EntityWriteResult(
                    ['id' => 'composite-comment-key'],
                    ['quoteId' => 'quote-agent'],
                    'quote_comment',
                    EntityWriteResult::OPERATION_INSERT,
                ),
                new EntityWriteResult(
                    'comment-valid',
                    ['quoteId' => ''],
                    'quote_comment',
                    EntityWriteResult::OPERATION_INSERT,
                ),
            ],
            $context,
        );

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);
        $subscriber->onQuoteCommentWritten($event);
    }

    public function testIgnoresSnapshotVersionProjectionBeforeLiveWrite(): void
    {
        $this->gateway->expects(self::never())->method('fetchSnapshot');
        $this->gateway->expects(self::never())->method('updateQuote');
        $this->bus->expects(self::never())->method('dispatch');

        $snapshotVersionId = Uuid::randomHex();
        self::assertNotSame(Defaults::LIVE_VERSION, $snapshotVersionId);

        $event = $this->createCommentWrittenEvent(
            'comment-0',
            [
                'quoteId' => 'quote-agent',
                'quoteVersionId' => $snapshotVersionId,
                'comment' => 'Agent offer details here.',
            ],
            Context::createDefaultContext(),
        );

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);
        $subscriber->onQuoteCommentWritten($event);
    }

    public function testSkipsReplayOfExactAgentCommentId(): void
    {
        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $snapshot = $this->createSnapshot($revision, [MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID => 'comment-0']);

        $this->gateway->expects(self::once())->method('fetchSnapshot')->with('quote-agent')->willReturn($snapshot);
        $this->gateway->expects(self::never())->method('updateQuote');
        $this->bus->expects(self::never())->method('dispatch');

        $event = $this->createCommentWrittenEvent(
            'comment-0',
            [
                'quoteId' => 'quote-agent',
                'customerId' => null,
                'employeeId' => null,
                'comment' => 'Agent offer details here.',
            ],
            Context::createDefaultContext(),
        );

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);
        $subscriber->onQuoteCommentWritten($event);
    }

    public function testDispatchesLaterAuthorlessCommentWithSameTextAndDifferentId(): void
    {
        $revision = new QuoteRevision(
            '018b449b2ba170a4a589cf8cb59a35e4',
            new \DateTimeImmutable('2026-08-27 12:00:00.123456'),
        );
        $snapshot = $this->createSnapshot($revision, [
            MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID => 'earlier-agent-comment-id',
        ]);

        $this->gateway->expects(self::once())->method('fetchSnapshot')->with('quote-agent')->willReturn($snapshot);
        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn(ServiceQuoteMessage $message): bool => self::isExpectedMessage($message, $revision),
            ))
            ->willReturn(new Envelope(new \stdClass()));

        $event = $this->createCommentWrittenEvent(
            'different-comment-id',
            [
                'quoteId' => 'quote-agent',
                'customerId' => null,
                'employeeId' => null,
                'comment' => 'Agent offer details here.',
            ],
            Context::createDefaultContext(),
        );

        $subscriber = new QuoteServicingSubscriber($this->bus, $this->gateway);
        $subscriber->onQuoteCommentWritten($event);
    }
}
