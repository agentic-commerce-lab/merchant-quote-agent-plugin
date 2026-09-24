<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use MerchantQuoteAgentPlugin\Audit\TraceDraft;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Uuid\Uuid;

final class DecisionRecordWriterTest extends TestCase
{
    public function testTheDraftBecomesOneCreatePayloadWithAGeneratedId(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $event = $this->createStub(EntityWrittenContainerEvent::class);
        $captured = [];

        $repository
            ->expects(self::once())
            ->method('create')
            ->willReturnCallback(static function (array $payload) use (
                &$captured,
                $event,
            ): EntityWrittenContainerEvent {
                $captured = $payload;

                return $event;
            });

        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        $draft->outcome = 'offered';
        $draft->band = 'grant';
        $draft->durationMs = 42;
        $draft->violations = [];

        (new DecisionRecordWriter($repository, $this->createMock(EntityRepository::class), new NullLogger()))->write(
            $draft,
        );

        self::assertCount(1, $captured);
        self::assertTrue(Uuid::isValid($captured[0]['id']), 'The payload must carry a primary key.');
        self::assertSame(
            $draft->id,
            $captured[0]['id'],
            'The writer must use the id begin() generated, so trace rows and log lines can point at it.',
        );
        self::assertSame('offered', $captured[0]['outcome']);
        self::assertSame(42, $captured[0]['durationMs']);
    }

    public function testTheStartedAtWorkingFieldIsNotWritten(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $event = $this->createStub(EntityWrittenContainerEvent::class);
        $captured = [];

        $repository
            ->method('create')
            ->willReturnCallback(static function (array $payload) use (
                &$captured,
                $event,
            ): EntityWrittenContainerEvent {
                $captured = $payload;

                return $event;
            });

        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        $draft->startedAt = microtime(true);

        (new DecisionRecordWriter($repository, $this->createMock(EntityRepository::class), new NullLogger()))->write(
            $draft,
        );

        self::assertArrayNotHasKey('startedAt', $captured[0]);
    }

    public function testTheTraceIsWrittenAfterTheDecisionUnderTheDecisionsId(): void
    {
        $order = [];
        $event = $this->createStub(EntityWrittenContainerEvent::class);
        $records = $this->createMock(EntityRepository::class);
        $records
            ->method('create')
            ->willReturnCallback(static function () use (&$order, $event) {
                $order[] = 'decision';

                return $event;
            });
        $traces = $this->createMock(EntityRepository::class);
        $captured = [];
        $traces
            ->expects(self::once())
            ->method('create')
            ->willReturnCallback(static function (array $payload) use (&$order, &$captured, $event) {
                $order[] = 'trace';
                $captured = $payload;

                return $event;
            });

        $draft = new DecisionDraft();
        $draft->quoteId = Uuid::randomHex();
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 1], null);
        TraceDraft::appendTo($draft, TraceKind::ReplyGuard, ['accepted' => false], null);

        (new DecisionRecordWriter($records, $traces, new NullLogger()))->write($draft);

        self::assertSame(['decision', 'trace'], $order);
        self::assertCount(2, $captured);
        self::assertSame($draft->id, $captured[0]['decisionId']);
        self::assertSame('reply_guard', $captured[1]['kind']);
    }

    public function testAPassWithNoTraceMakesNoTraceWrite(): void
    {
        $records = $this->createMock(EntityRepository::class);
        $records->method('create')->willReturn($this->createStub(EntityWrittenContainerEvent::class));
        $traces = $this->createMock(EntityRepository::class);
        $traces->expects(self::never())->method('create');

        (new DecisionRecordWriter($records, $traces, new NullLogger()))->write(new DecisionDraft());
    }

    public function testAFailedTraceWriteIsLoggedAndTheDecisionStands(): void
    {
        $records = $this->createMock(EntityRepository::class);
        $records
            ->expects(self::once())
            ->method('create')
            ->willReturn($this->createStub(EntityWrittenContainerEvent::class));
        $traces = $this->createMock(EntityRepository::class);
        $traces->method('create')->willThrowException(new \RuntimeException('JSON column rejected'));
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $lines = [];

            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = [(string) $level, (string) $message];
            }
        };

        $draft = new DecisionDraft();
        TraceDraft::appendTo($draft, TraceKind::QuoteBefore, ['lineCount' => 1], null);

        (new DecisionRecordWriter($records, $traces, $logger))->write($draft);

        self::assertSame('error', $logger->lines[0][0] ?? null, 'A lost trace must be loud.');
    }
}
