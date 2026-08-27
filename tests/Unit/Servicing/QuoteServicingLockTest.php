<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class QuoteServicingLockTest extends TestCase
{
    public function testTwoLocksForOneQuoteAreMutuallyExclusive(): void
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://localhost');

        $first = $locks->for('quote-1');
        $second = $locks->for('quote-1');

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
    }

    public function testLocksForDifferentQuotesDoNotContend(): void
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://localhost');

        self::assertTrue($locks->for('quote-1')->acquire());
        self::assertTrue($locks->for('quote-2')->acquire());
    }

    public function testAReleasedLockCanBeReacquired(): void
    {
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://localhost');

        $first = $locks->for('quote-1');
        self::assertTrue($first->acquire());
        $first->release();

        self::assertTrue($locks->for('quote-1')->acquire());
    }

    public function testAHostLocalStoreIsWarnedAboutExactlyOnce(): void
    {
        $logger = self::collectingLogger();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'flock', $logger);

        $locks->for('quote-1');
        $locks->for('quote-2');

        self::assertCount(1, $logger->records);
        self::assertStringContainsString('host-local', $logger->records[0]);
    }

    public function testASemaphoreStoreIsAlsoHostLocal(): void
    {
        $logger = self::collectingLogger();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'semaphore', $logger);

        $locks->for('quote-1');

        self::assertCount(1, $logger->records);
    }

    public function testASharedStoreIsNotWarnedAbout(): void
    {
        $logger = self::collectingLogger();
        $locks = new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://cache:6379', $logger);

        $locks->for('quote-1');

        self::assertSame([], $logger->records);
    }

    /** @return AbstractLogger&object{records: list<string>} */
    private static function collectingLogger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<string, mixed> $context
             */
            #[\Override]
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        };
    }
}
