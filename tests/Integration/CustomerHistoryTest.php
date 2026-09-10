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

/** Real-shop company boundaries and values, checked against independent SQL.  *
 * @mago-expect lint:too-many-methods
 * Each test proves one isolation or accuracy property against the real shop.
 * They share the same expensive fixtures (a seeded second company with its own
 * orders), so splitting the class would duplicate that setup rather than
 * clarify anything.
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
     *     busiest first. A quote-heavy customer need not have ever ordered.
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
        $history = self::factory()->for('', '');

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

        $seen = self::factory()->for($customerId, '')->summary()->quotes->seen;

        // The read is capped at 25, so assert the property rather than equality
        // when the company has more live quotes than that.
        self::assertSame(min($liveCount, 25), $seen);
        self::assertLessThan($rowCount, $seen, 'A snapshot version was counted as a second quote.');
    }

    public function testTheServicedQuoteIsAbsentFromItsOwnHistory(): void
    {
        // "History" must mean OTHER quotes. If the quote under negotiation shows
        // up in its own account history, a buyer writing "you already gave us
        // 15%" is indistinguishable from a spent precedent on a closed quote --
        // and granting it again discounts a total that already came down by it.
        // That is the double-concession QuoteBaseline (#49) prevents, arriving
        // through a side channel.
        $customers = self::customersByQuoteCount();
        self::assertNotSame([], $customers);
        [$customerId, $liveCount] = $customers[0];
        self::assertGreaterThan(1, $liveCount, 'This proof needs a customer with more than one quote.');

        $numbers = self::quoteNumbersOf($customerId);
        self::assertNotSame([], $numbers);

        // Service each of this customer's quotes in turn: every one must be
        // missing from the history read taken while it is the serviced quote.
        $ids = self::connection(static::getContainer())
            ->fetchAllAssociative('SELECT LOWER(HEX(id)) AS id, quote_number FROM quote'
            . ' WHERE customer_id = UNHEX(:customer) AND version_id = UNHEX(:live) LIMIT 5', [
                'customer' => $customerId,
                'live' => Defaults::LIVE_VERSION,
            ]);
        self::assertNotSame([], $ids);

        foreach ($ids as $row) {
            $entries = self::factory()
                ->for($customerId, (string) $row['id'])
                ->quotes();
            $seen = array_map(static fn(object $e): string => $e->quoteNumber, $entries);

            self::assertNotContains(
                (string) $row['quote_number'],
                $seen,
                sprintf('Quote %s appeared in its own history.', (string) $row['quote_number']),
            );
            // The exclusion must remove exactly one quote, not the whole account.
            self::assertSame(min($liveCount, 25) - 1, \count($entries));
        }
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

        $entries = self::factory()->for($mine, '')->quotes();
        $mineNumbers = array_map(static fn(object $e): string => $e->quoteNumber, $entries);

        self::assertSame([], array_intersect($mineNumbers, $theirNumbers));
    }

    public function testOrdersMatchIndependentSqlIncludingNetCurrencyAndNewestTen(): void
    {
        $customers = self::customersWithOrders();
        self::assertGreaterThanOrEqual(2, \count($customers), 'Run scripts/seed-order-history.php first.');
        $reference = new CustomerHistoryReference(self::connection(static::getContainer()));

        foreach ($customers as [$customerId]) {
            $expected = $reference->orders($customerId);
            $history = self::factory()->for($customerId, '')->orders();
            self::assertSame(\count($expected), $history->stats->count);
            self::assertSame($expected[0]['date'], $history->stats->lastOrderAt?->format('Y-m-d H:i:s'));
            $currencies = array_unique(array_column($expected, 'currency'));
            if (\count($currencies) === 1 && $currencies[0] !== null) {
                self::assertEqualsWithDelta(
                    array_sum(array_column($expected, 'net')),
                    $history->stats->lifetimeNet,
                    0.001,
                );
                self::assertSame($currencies[0], $history->stats->currencyIso);
                self::assertNull($history->stats->unavailableReason);
            } else {
                self::assertNull($history->stats->lifetimeNet);
                self::assertNull($history->stats->currencyIso);
                self::assertNotEmpty($history->stats->unavailableReason);
            }
            self::assertSame(
                array_slice($expected, offset: 0, length: 10),
                array_map(static fn($order): array => [
                    'number' => $order->orderNumber,
                    'net' => $order->amountNet,
                    'currency' => $order->currencyIso,
                    'date' => $order->orderedAt?->format('Y-m-d H:i:s'),
                ], $history->recent),
            );
        }
    }

    public function testProductPurchasesMatchSqlAndExcludeAnotherCompanyBuyingTheSameSku(): void
    {
        $customers = self::customersWithOrders();
        self::assertGreaterThanOrEqual(2, \count($customers), 'Run scripts/seed-order-history.php first.');
        [$mine] = $customers[0];
        [$theirs] = $customers[1];
        $reference = new CustomerHistoryReference(self::connection(static::getContainer()));
        $products = $reference->sharedProducts($mine, $theirs);
        self::assertNotEmpty($products, 'Two companies must have real orders containing the same SKU.');

        foreach ($products as $productId) {
            $expected = $reference->purchases($mine, $productId);
            $foreign = $reference->purchases($theirs, $productId);
            self::assertNotEmpty($expected);
            self::assertNotEmpty($foreign);
            $actual = array_map(static fn($purchase): array => [
                'quantity' => $purchase->quantity,
                'net' => $purchase->unitPriceNet,
                'currency' => $purchase->currencyIso,
                'date' => $purchase->orderedAt?->format('Y-m-d H:i:s'),
            ], self::factory()->for($mine, '')->productPurchases($productId));
            self::assertSame($expected, $actual);
            $foreignOnly = array_filter(
                $foreign,
                static fn(array $purchase): bool => !\in_array($purchase, $expected, strict: true),
            );
            self::assertNotEmpty($foreignOnly, 'The fixture needs a distinguishable foreign same-SKU purchase.');
            foreach ($foreignOnly as $purchase) {
                self::assertNotContains($purchase, $actual, 'A foreign same-SKU purchase leaked into this company.');
            }
        }
    }

    public function testASecondCompanysOrdersAreAbsent(): void
    {
        $customers = self::customersWithOrders();

        self::assertGreaterThanOrEqual(2, \count($customers), 'Run scripts/seed-order-history.php first.');

        [$mine] = $customers[0];
        [$theirs] = $customers[1];

        $theirNumbers = array_column(
            (new CustomerHistoryReference(self::connection(static::getContainer())))->orders($theirs),
            'number',
        );
        self::assertNotSame([], $theirNumbers);

        $entries = self::factory()->for($mine, '')->orders()->recent;
        $mineNumbers = array_map(static fn(object $e): string => $e->orderNumber, $entries);

        self::assertSame([], array_intersect($mineNumbers, $theirNumbers));
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
