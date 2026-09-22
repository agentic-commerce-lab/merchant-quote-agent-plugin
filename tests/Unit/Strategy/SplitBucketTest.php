<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use MerchantQuoteAgentPlugin\Strategy\SplitBucket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The frozen fixture. Changing any value below changes which arm every
 * company in every live split lands in, on every shop, silently -- the
 * negotiation looks identical, only the posture differs. Treat a failure here
 * as "the refactor is wrong", never as "the fixture is stale".
 */
final class SplitBucketTest extends TestCase
{
    private const CHANNEL = '0189abcdef0123456789abcdef012345';

    /** @return iterable<string, array{string, int}> */
    public static function frozen(): iterable
    {
        yield 'customer a' => ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1581];
        yield 'customer b' => ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 2038];
        yield 'customer c' => ['cccccccccccccccccccccccccccccccc', 6857];
        yield 'customer d' => ['dddddddddddddddddddddddddddddddd', 3978];
    }

    #[DataProvider('frozen')]
    public function testTheBucketNeverMoves(string $customerId, int $expected): void
    {
        self::assertSame($expected, SplitBucket::of($customerId, self::CHANNEL));
    }

    public function testTheBucketIsStableAcrossCalls(): void
    {
        $first = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::CHANNEL);
        $second = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::CHANNEL);

        self::assertSame($first, $second);
    }

    public function testTheSalesChannelIsPartOfTheHash(): void
    {
        $here = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', self::CHANNEL);
        $there = SplitBucket::of('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'ffffffffffffffffffffffffffffffff');

        self::assertNotSame($here, $there);
    }

    public function testEveryBucketIsInRange(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $bucket = SplitBucket::of(str_pad((string) $i, 32, '0', \STR_PAD_LEFT), self::CHANNEL);

            self::assertGreaterThanOrEqual(0, $bucket);
            self::assertLessThan(10000, $bucket);
        }
    }

    /**
     * Not a uniformity proof -- 500 samples cannot be one. It is a smoke
     * alarm for a hash that collapsed to a constant, which is the realistic
     * way this breaks and the way a range check alone would not notice.
     */
    public function testTheHashDoesNotCollapse(): void
    {
        $seen = [];

        for ($i = 0; $i < 500; $i++) {
            $seen[SplitBucket::of(str_pad((string) $i, 32, '0', \STR_PAD_LEFT), self::CHANNEL)] = true;
        }

        self::assertGreaterThan(400, \count($seen));
    }
}
