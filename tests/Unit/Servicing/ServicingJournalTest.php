<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\ServicingJournal;
use MerchantQuoteAgentPlugin\Servicing\SkipContext;
use MerchantQuoteAgentPlugin\Servicing\SkipReason;
use MerchantQuoteAgentPlugin\Servicing\SkipSource;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeTraceWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ServicingJournalTest extends TestCase
{
    public function testItWritesAClosedSkipEventAndDelegatesExistingLogs(): void
    {
        $writer = new FakeTraceWriter();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('log')->with('warning', 'busy', ['quoteId' => 'q1']);
        $journal = new ServicingJournal($logger, $writer);

        $journal->warning('busy', ['quoteId' => 'q1']);
        $journal->skip(
            SkipSource::Handler,
            SkipReason::LockBusy,
            SkipContext::forMessage(new \MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage(
                'q1',
                'comment_written',
            )),
        );

        self::assertCount(1, $writer->events);
        $event = $writer->events[0];
        self::assertSame('skip', $event->kind->value);
        self::assertSame('q1', $event->quoteId);
        self::assertNull($event->customerId);
        self::assertNull($event->content);
        self::assertSame(
            ['source' => 'handler', 'reason' => 'lock_busy', 'trigger' => 'comment_written', 'attempt' => null],
            $event->meta,
        );
    }

    public function testInvalidTriggerTextNeverEntersTheAlwaysExportedMeta(): void
    {
        $writer = new FakeTraceWriter();
        $journal = new ServicingJournal($this->createMock(LoggerInterface::class), $writer);

        $journal->skip(
            SkipSource::Handler,
            SkipReason::StaleTrigger,
            SkipContext::forMessage(new \MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage(
                'q1',
                'Anna@example.com',
            )),
        );

        self::assertNull($writer->events[0]->meta['trigger']);
    }

    public function testAWriterBugDoesNotChangeTheDelivery(): void
    {
        $writer = new FakeTraceWriter();
        $writer->failure = new \RuntimeException('writer failed');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $journal = new ServicingJournal($logger, $writer);

        $journal->skip(
            SkipSource::Handler,
            SkipReason::NothingNew,
            SkipContext::forMessage(new \MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage(
                'q1',
                'comment_written',
            )),
        );

        self::assertSame([], $writer->events);
    }
}
