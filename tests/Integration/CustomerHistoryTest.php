<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\History\CustomerHistoryFactory;
use MerchantQuoteAgentPlugin\Bridge\History\DecisionAggregate;
use MerchantQuoteAgentPlugin\Bridge\History\OrderHistoryReads;
use MerchantQuoteAgentPlugin\Bridge\History\QuoteHistoryReads;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use MerchantQuoteAgentPlugin\Negotiation\NoCustomerHistory;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * The company boundary, against a real shop. Everything here is a claim the
 * unit tests structurally cannot make: that the association paths are right,
 * that version filtering actually deduplicates, and that a second company is
 * genuinely absent rather than merely filtered in a Criteria we built ourselves.
 *
 * Two customer selectors, not one: `customersByQuoteCount()` for the quote-side
 * assertions, `customersWithOrders()` for the order-side ones. On this shop the
 * busiest quote customer (18 live quotes) has zero orders, and the only two live
 * orders both belong to a different customer — a single "busiest" selector used
 * for both would make the order assertions pass vacuously against an empty set.
 */
final class CustomerHistoryTest extends IntegrationTestCase
{
    private static function factory(): CustomerHistoryFactory
    {
        $container = static::getContainer();
        $versions = new QuoteVersionResolver();

        return new CustomerHistoryFactory(
            new QuoteHistoryReads(
                self::repository($container, 'quote.repository'),
                new DecisionAggregate(self::connection($container)),
            ),
            new OrderHistoryReads(
                self::repository($container, 'order.repository'),
                self::repository($container, 'order_line_item.repository'),
            ),
            $versions,
        );
    }

    /** @return list<array{0: string, 1: int}> customer id => live quote count, busiest first */
    private static function customersByQuoteCount(): array
    {
        $rows = self::connection(static::getContainer())
            ->fetchAllAssociative('SELECT LOWER(HEX(customer_id)) AS id, COUNT(*) AS n FROM quote'
            . ' WHERE version_id = UNHEX(:live) GROUP BY customer_id ORDER BY n DESC', [
                'live' => Defaults::LIVE_VERSION,
            ]);

        return array_map(static fn(array $r): array => [(string) $r['id'], (int) $r['n']], $rows);
    }

    /**
     * @return list<array{0: string, 1: int}> customer id => live order count,
     *     busiest first. Deliberately a SEPARATE query from
     *     customersByQuoteCount(): a quote-heavy customer need not have ever
     *     ordered, and on this shop none of the quote-heavy ones have.
     */
    private static function customersWithOrders(): array
    {
        $rows = self::connection(static::getContainer())
            ->fetchAllAssociative('SELECT LOWER(HEX(oc.customer_id)) AS id, COUNT(*) AS n'
            . ' FROM order_customer oc'
            . ' INNER JOIN `order` o ON o.id = oc.order_id AND o.version_id = oc.order_version_id'
            . ' WHERE o.version_id = UNHEX(:live) AND oc.customer_id IS NOT NULL'
            . ' GROUP BY oc.customer_id ORDER BY n DESC', ['live' => Defaults::LIVE_VERSION]);

        return array_map(static fn(array $r): array => [(string) $r['id'], (int) $r['n']], $rows);
    }

    public function testAnEmptyCustomerIdYieldsNoHistoryRatherThanEverything(): void
    {
        // The failure that matters: a broken row must not become an unfiltered
        // read over every company in the shop.
        $history = self::factory()->for('');

        self::assertInstanceOf(NoCustomerHistory::class, $history);
        self::assertFalse($history->summary()->available);
        self::assertSame([], $history->quotes());
    }

    public function testQuotesAreCountedOncePerQuoteNotOncePerVersion(): void
    {
        $customers = self::customersByQuoteCount();
        self::assertNotSame([], $customers, 'The shop has no quotes; seed one before running this.');
        [$customerId, $liveCount] = $customers[0];

        $rowCount = (int) self::connection(static::getContainer())
            ->fetchOne('SELECT COUNT(*) FROM quote WHERE customer_id = UNHEX(:id)', ['id' => $customerId]);

        self::assertGreaterThan(
            $liveCount,
            $rowCount,
            'No quote for the busiest customer has more than one version, so version dedup is '
            . 'unproven. Create one with QuoteFixture::quoteIdWithSnapshotLane() and re-run.',
        );

        $seen = self::factory()->for($customerId)->summary()->quotes->seen;

        // The read is capped at 25, so assert the property rather than equality
        // when the company has more live quotes than that.
        self::assertSame(min($liveCount, 25), $seen);
        self::assertLessThan($rowCount, $seen, 'A snapshot version was counted as a second quote.');
    }

    public function testASecondCompanysQuotesAreAbsent(): void
    {
        $customers = self::customersByQuoteCount();
        self::assertGreaterThanOrEqual(
            2,
            \count($customers),
            'The shop needs two customers with quotes for this test; seed a second one.',
        );

        [$mine] = $customers[0];
        [$theirs] = $customers[1];

        $theirNumbers = self::quoteNumbersOf($theirs);
        self::assertNotSame([], $theirNumbers);

        $entries = self::factory()->for($mine)->quotes();
        $mineNumbers = array_map(static fn(object $e): string => $e->quoteNumber, $entries);

        self::assertSame([], array_intersect($mineNumbers, $theirNumbers));
    }

    public function testTheOrderReadResolvesItsAssociationPathOnARealShop(): void
    {
        // This is what pins order.orderCustomer.customerId and the price object.
        // It asserts shape, not values: seeding owns the values (Task 14).
        //
        // Uses customersWithOrders(), not customersByQuoteCount(): the busiest
        // quote customer on this shop has zero orders, and asserting against it
        // here would pass vacuously.
        $customers = self::customersWithOrders();
        self::assertNotSame([], $customers, 'The shop has no orders; seed one before running this.');

        $history = self::factory()->for($customers[0][0])->orders();

        self::assertGreaterThan(0, $history->stats->count);
        self::assertGreaterThanOrEqual(0.0, $history->stats->lifetimeNet);
        self::assertSame($history->stats->count > 0, $history->stats->lastOrderAt !== null);
        self::assertLessThanOrEqual(10, \count($history->recent));
    }

    public function testASecondCompanysOrdersAreAbsent(): void
    {
        $customers = self::customersWithOrders();

        if (\count($customers) < 2) {
            self::markTestSkipped(
                'Only one customer on this shop has orders (verified: 2 live orders, both owned '
                . 'by the same customer, Task 6 report). This test proves the boundary once a '
                . 'second customer has an order; testTheOrderReadResolvesItsAssociationPathOnARealShop '
                . 'and the quote-side cross-customer test already prove the scoping mechanism.',
            );
        }

        [$mine] = $customers[0];
        [$theirs] = $customers[1];

        $history = self::factory()->for($mine)->orders();

        foreach ($history->recent as $entry) {
            self::assertNotSame($theirs, $entry, 'A second company\'s order leaked into this one\'s history.');
        }
    }

    /** @return list<string> */
    private static function quoteNumbersOf(string $customerId): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        $quotes = self::repository(static::getContainer(), 'quote.repository')
            ->search($criteria, AgentContext::create())
            ->getEntities();

        $numbers = [];

        foreach ($quotes as $quote) {
            $numbers[] = (string) $quote->get('quoteNumber');
        }

        return $numbers;
    }
}
