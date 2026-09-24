<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\TraceWrite;
use MerchantQuoteAgentPlugin\Audit\TraceWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;

final class TraceWriterTest extends TestCase
{
    public function testItWritesAnOutsidePassEventWithOnlyDeclaredMeta(): void
    {
        $repository = self::repository();
        $writer = new TraceWriter($repository, $this->createMock(LoggerInterface::class));

        $writer->write(
            new TraceWrite(
                TraceKind::Skip,
                [
                    'source' => 'handler',
                    'reason' => 'nothing_new',
                    'trigger' => 'comment_written',
                    'attempt' => 2,
                    'buyer' => 'Anna',
                ],
                null,
                'quote-1',
                'customer-1',
            ),
        );

        self::assertCount(1, $repository->rows);
        $row = $repository->rows[0];
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row['id']);
        self::assertNull($row['decisionId']);
        self::assertNull($row['position']);
        self::assertSame('quote-1', $row['quoteId']);
        self::assertSame('customer-1', $row['customerId']);
        self::assertSame('skip', $row['kind']);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['occurredAt']);
        self::assertSame(
            ['source' => 'handler', 'reason' => 'nothing_new', 'trigger' => 'comment_written', 'attempt' => 2],
            $row['meta'],
        );
        self::assertNull($row['content']);
    }

    public function testItCapsContentAndRecordsTheCut(): void
    {
        $repository = self::repository();
        $writer = new TraceWriter($repository, $this->createMock(LoggerInterface::class));

        $writer->write(new TraceWrite(TraceKind::Skip, ['source' => 'handler'], ['body' => str_repeat('x', 65_537)]));

        self::assertSame(65_536, strlen($repository->rows[0]['content']['body']));
        self::assertSame(['body'], $repository->rows[0]['meta']['truncated']);
    }

    public function testARepositoryFailureIsLoggedAndNeverEscapes(): void
    {
        $repository = self::repository();
        $repository->failure = new \RuntimeException('table unavailable');
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::isType('string'),
                self::callback(static fn(array $context): bool => $context['exception'] instanceof \RuntimeException),
            );

        (new TraceWriter($repository, $logger))->write(new TraceWrite(TraceKind::Skip, ['reason' => 'nothing_new']));

        self::assertSame([], $repository->rows);
    }

    private static function repository(): EntityRepository
    {
        return new class extends EntityRepository {
            /** @var list<array<string, mixed>> */
            public array $rows = [];

            public ?\Throwable $failure = null;

            public function __construct() {}

            /** @param array<int, array<string, mixed>> $data */
            #[\Override]
            public function create(array $data, Context $context): EntityWrittenContainerEvent
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                $this->rows = [...$this->rows, ...$data];

                return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
            }
        };
    }
}
