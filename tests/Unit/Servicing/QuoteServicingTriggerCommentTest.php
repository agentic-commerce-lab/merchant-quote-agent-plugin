<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingTrigger;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;

final class QuoteServicingTriggerCommentTest extends TestCase
{
    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testAnInsertedCommentQueuesItsQuote(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::commentEvent([QuoteTriggerEventFixture::commentInsert('q1')]);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0] ?? null;
        self::assertNotNull($message);
        self::assertSame('comment_written', $message->reason);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testTwoCommentsOnOneQuoteQueueItOnce(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::commentEvent([
            QuoteTriggerEventFixture::commentInsert('q1'),
            QuoteTriggerEventFixture::commentInsert('q1'),
        ]);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertCount(1, $bus->messages);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testCommentsOnTwoQuotesQueueBoth(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::commentEvent([
            QuoteTriggerEventFixture::commentInsert('q1'),
            QuoteTriggerEventFixture::commentInsert('q2'),
        ]);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertCount(2, $bus->messages);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testAnEditedCommentQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $update = new EntityWriteResult(
            'c1',
            ['quoteId' => 'q1', 'comment' => 'edited'],
            'quote_comment',
            EntityWriteResult::OPERATION_UPDATE,
        );

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([$update]));

        self::assertSame([], $bus->messages);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testACommentInsertWithNoQuoteIdQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $orphan = new EntityWriteResult('c1', ['comment' => 'x'], 'quote_comment', EntityWriteResult::OPERATION_INSERT);

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten(QuoteTriggerEventFixture::commentEvent([$orphan]));

        self::assertSame([], $bus->messages);
    }

    /** @throws \Symfony\Component\Messenger\Exception\ExceptionInterface */
    public function testAnAgentAuthoredCommentQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::commentEvent([QuoteTriggerEventFixture::commentInsert(
            'q1',
        )], AgentContext::create());

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertSame([], $bus->messages);
    }

    /**
     * The measured case the version filter exists for. One addComment() fires
     * TWO quote_comment.written events — the live insert and SwagCommercial's
     * snapshot mirror — and the mirror's Context has lost AgentContext::STATE
     * because Context::createWithVersionId() does not carry states. Without this
     * filter the agent re-triggers itself, and a buyer comment queues twice.
     *
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     */
    public function testACommentWrittenOnTheSnapshotVersionQueuesNothing(): void
    {
        $bus = QuoteTriggerEventFixture::collectingBus();
        $event = QuoteTriggerEventFixture::commentEvent([QuoteTriggerEventFixture::commentInsert(
            'q1',
        )], QuoteTriggerEventFixture::snapshotContext());

        (new QuoteServicingTrigger($bus))->onQuoteCommentWritten($event);

        self::assertSame([], $bus->messages);
    }
}
