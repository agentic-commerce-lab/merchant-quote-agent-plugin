<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\History\DecisionAggregate;
use PHPUnit\Framework\TestCase;

/**
 * Shopware's connection is built with PDO::ATTR_STRINGIFY_FETCHES, so every
 * scalar column `fetchAllAssociative()` hands back is a string -- "1", "0",
 * "5" -- never a native int/float, and never anything but a string for a
 * non-NULL value. `Connection` cannot be constructed against a real database
 * in a unit test, so it is mocked here exactly as the rest of this repo mocks
 * it (see AcAgentGrantReaderTest): the fake supplies stringified rows, the
 * REAL DecisionAggregate::forQuotes() consumes them, and the assertions are
 * on its real output. That is the one seam this cast can be proven at without
 * a live database; Task 6's integration test proves the query and the driver
 * option together.
 */
final class DecisionAggregateTest extends TestCase
{
    private const QUOTE_PENDING = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const QUOTE_DENIED = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const QUOTE_GRANTED_ZERO = 'cccccccccccccccccccccccccccccccc';

    private const QUOTE_GRANTED_FIVE = 'dddddddddddddddddddddddddddddddd';

    /** @param list<array{quote_id: string, authorized: string|null, discount_percent_granted: string|null, created_at: string}> $rows */
    private function aggregateOver(array $rows): DecisionAggregate
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new DecisionAggregate($connection);
    }

    public function testAStringifiedAuthorizedColumnStillCountsAsAnOfferMade(): void
    {
        // "1" === 1 is false. Without a cast at the boundary, every real
        // offer -- which is exactly what this column holds in production --
        // silently reads as zero.
        $rollup = $this->aggregateOver([
            [
                'quote_id' => self::QUOTE_PENDING,
                'authorized' => null,
                'discount_percent_granted' => null,
                'created_at' => '2026-08-01 10:00:00.000',
            ],
            [
                'quote_id' => self::QUOTE_DENIED,
                'authorized' => '0',
                'discount_percent_granted' => null,
                'created_at' => '2026-08-02 10:00:00.000',
            ],
            [
                'quote_id' => self::QUOTE_GRANTED_FIVE,
                'authorized' => '1',
                'discount_percent_granted' => '5',
                'created_at' => '2026-08-03 10:00:00.000',
            ],
        ])->forQuotes([self::QUOTE_PENDING, self::QUOTE_DENIED, self::QUOTE_GRANTED_FIVE]);

        self::assertSame(1, $rollup->offersMade);
        self::assertSame([self::QUOTE_GRANTED_FIVE], $rollup->quoteIdsWithOffers);
    }

    public function testAStringifiedGrantReachesTheFloatParameterWithoutThrowing(): void
    {
        // discount_percent_granted is a DOUBLE column; stringified, "5" is
        // passed to DecisionRollup's `?float` parameter. Under
        // declare(strict_types=1) a string there is a TypeError, not a silent
        // coercion -- so this proves the cast happens before that call, not
        // just that the numbers come out right.
        $rollup = $this->aggregateOver([
            [
                'quote_id' => self::QUOTE_GRANTED_FIVE,
                'authorized' => '1',
                'discount_percent_granted' => '5',
                'created_at' => '2026-08-01 10:00:00.000',
            ],
        ])->forQuotes([self::QUOTE_GRANTED_FIVE]);

        self::assertSame(5.0, $rollup->lastGrantedDiscountPercent);
        self::assertSame([self::QUOTE_GRANTED_FIVE => 5.0], $rollup->grantedByQuote);
    }

    public function testAStringifiedZeroGrantSurvivesAsZeroNotAsNull(): void
    {
        // "0" is falsy in PHP. A cast written as `$row['x'] ?: null` would
        // collapse a genuine zero-percent grant into "we don't know", telling
        // the model the opposite of what happened.
        $rollup = $this->aggregateOver([
            [
                'quote_id' => self::QUOTE_GRANTED_ZERO,
                'authorized' => '1',
                'discount_percent_granted' => '0',
                'created_at' => '2026-08-01 10:00:00.000',
            ],
        ])->forQuotes([self::QUOTE_GRANTED_ZERO]);

        self::assertSame([self::QUOTE_GRANTED_ZERO => 0.0], $rollup->grantedByQuote);
        self::assertSame(0.0, $rollup->lastGrantedDiscountPercent);
    }

    public function testNullAuthorizedAndNullGrantPassThroughAsNullNotZero(): void
    {
        // Most of the real table is pending rows like this one: escalated,
        // not yet ruled on. NULL must stay NULL through the cast.
        $rollup = $this->aggregateOver([
            [
                'quote_id' => self::QUOTE_PENDING,
                'authorized' => null,
                'discount_percent_granted' => null,
                'created_at' => '2026-08-01 10:00:00.000',
            ],
        ])->forQuotes([self::QUOTE_PENDING]);

        self::assertSame(0, $rollup->offersMade);
        self::assertSame([], $rollup->quoteIdsWithOffers);
        self::assertSame([], $rollup->grantedByQuote);
        self::assertNull($rollup->lastGrantedDiscountPercent);
    }
}
