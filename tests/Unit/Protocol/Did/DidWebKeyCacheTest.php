<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Did;

use MerchantQuoteAgentPlugin\Protocol\Did\DidWebKeyCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DidWebKeyCacheTest extends TestCase
{
    private const METHOD = 'did:web:buyer.example#key-1';

    public function testASecondLookupDoesNotResolveAgain(): void
    {
        $cache = new DidWebKeyCache(new ArrayAdapter());
        $calls = 0;
        $resolve = static function () use (&$calls): ?string {
            ++$calls;

            return 'pem';
        };

        self::assertSame('pem', $cache->through(self::METHOD, $resolve));
        self::assertSame('pem', $cache->through(self::METHOD, $resolve));
        self::assertSame(1, $calls);
    }

    public function testAFailureIsRememberedToo(): void
    {
        // The case most worth damping: a caller naming a host that will never
        // resolve. Without a negative entry that is the one path still hitting
        // the network on every attempt.
        $cache = new DidWebKeyCache(new ArrayAdapter());
        $calls = 0;
        $resolve = static function () use (&$calls): ?string {
            ++$calls;

            return null;
        };

        self::assertNull($cache->through(self::METHOD, $resolve));
        self::assertNull($cache->through(self::METHOD, $resolve));
        self::assertSame(1, $calls);
    }

    public function testTwoVerificationMethodsDoNotShareAnEntry(): void
    {
        $cache = new DidWebKeyCache(new ArrayAdapter());

        self::assertSame('a', $cache->through(self::METHOD, static fn(): string => 'a'));
        self::assertSame('b', $cache->through('did:web:other.example#key-1', static fn(): string => 'b'));
    }

    public function testWithoutAPoolEveryLookupResolves(): void
    {
        $cache = new DidWebKeyCache();
        $calls = 0;
        $resolve = static function () use (&$calls): ?string {
            ++$calls;

            return 'pem';
        };

        $cache->through(self::METHOD, $resolve);
        $cache->through(self::METHOD, $resolve);
        self::assertSame(2, $calls);
    }

    public function testABrokenCacheSlowsThePathDownRatherThanBreakingIt(): void
    {
        $pool = new class implements CacheItemPoolInterface {
            #[\Override]
            public function getItem(string $key): CacheItemInterface
            {
                throw new class extends \InvalidArgumentException implements \Psr\Cache\InvalidArgumentException {};
            }

            #[\Override]
            public function getItems(array $keys = []): iterable
            {
                return [];
            }

            #[\Override]
            public function hasItem(string $key): bool
            {
                return false;
            }

            #[\Override]
            public function clear(): bool
            {
                return true;
            }

            #[\Override]
            public function deleteItem(string $key): bool
            {
                return true;
            }

            #[\Override]
            public function deleteItems(array $keys): bool
            {
                return true;
            }

            #[\Override]
            public function save(CacheItemInterface $item): bool
            {
                return true;
            }

            #[\Override]
            public function saveDeferred(CacheItemInterface $item): bool
            {
                return true;
            }

            #[\Override]
            public function commit(): bool
            {
                return true;
            }
        };

        self::assertSame('pem', (new DidWebKeyCache($pool))->through(self::METHOD, static fn(): string => 'pem'));
    }
}
