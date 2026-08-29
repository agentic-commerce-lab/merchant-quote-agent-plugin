<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriter;
use PHPUnit\Framework\TestCase;
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

        (new DecisionRecordWriter($repository))->write($draft);

        self::assertCount(1, $captured);
        self::assertTrue(Uuid::isValid($captured[0]['id']), 'The writer must generate the primary key.');
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

        (new DecisionRecordWriter($repository))->write($draft);

        self::assertArrayNotHasKey('startedAt', $captured[0]);
    }
}
