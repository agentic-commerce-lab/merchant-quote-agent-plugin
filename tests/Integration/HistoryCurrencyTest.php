<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\History\CustomerScope;
use MerchantQuoteAgentPlugin\Bridge\History\DecisionAggregate;
use MerchantQuoteAgentPlugin\Bridge\History\OrderHistoryReads;
use MerchantQuoteAgentPlugin\Bridge\History\QuoteHistoryReads;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;

/** Real DAL currency joins and bucket counts; every mutation is transactionally rolled back. */
final class HistoryCurrencyTest extends IntegrationTestCase
{
    public function testOneCurrencyAcrossAllOrdersMakesLifetimeMoneyKnown(): void
    {
        $customerId = self::customerWithOrders();
        $orders = self::orderRows($customerId);
        self::setCurrency($orders, self::currencyId('EUR'));
        $history = self::reads()->history(self::scope($customerId));
        $expectedNet = array_sum(array_column($orders, 'amountNet'));

        self::assertSame(\count($orders), $history->stats->count);
        self::assertEqualsWithDelta($expectedNet, $history->stats->lifetimeNet, 0.001);
        self::assertSame('EUR', $history->stats->currencyIso);
        self::assertNull($history->stats->unavailableReason);
        self::assertNotEmpty($history->recent);
        foreach ($history->recent as $order) {
            self::assertSame('EUR', $order->currencyIso);
        }
        self::assertEquals($history->stats, self::reads()->stats(self::scope($customerId)));
    }

    public function testMixedCurrenciesWithholdTheSumAndKeepDetailsInOriginalCurrencies(): void
    {
        $customerId = self::customerWithOrders();
        $orders = self::orderRows($customerId);
        self::assertGreaterThanOrEqual(2, \count($orders));
        self::setCurrency($orders, self::currencyId('EUR'));
        self::setCurrency([$orders[0]], self::currencyId('USD'));
        $history = self::reads()->history(self::scope($customerId));

        self::assertSame(\count($orders), $history->stats->count);
        self::assertNull($history->stats->lifetimeNet);
        self::assertNull($history->stats->currencyIso);
        self::assertNotEmpty($history->stats->unavailableReason);
        self::assertSame(substr($orders[0]['orderedAt'], 0, 10), $history->stats->lastOrderAt?->format('Y-m-d'));
        self::assertSame('USD', $history->recent[0]->currencyIso);
        self::assertSame('EUR', $history->recent[1]->currencyIso);
        self::assertEqualsWithDelta($orders[0]['amountNet'], $history->recent[0]->amountNet, 0.001);

        $productId = self::connection(static::getContainer())
            ->fetchOne('SELECT LOWER(HEX(product_id)) FROM order_line_item'
            . ' WHERE order_id = UNHEX(:order) AND order_version_id = UNHEX(:live)'
            . ' AND version_id = UNHEX(:live) AND product_id IS NOT NULL LIMIT 1', [
                'order' => $orders[0]['id'],
                'live' => Defaults::LIVE_VERSION,
            ]);
        self::assertIsString($productId, 'The selected order needs a real product line.');
        $purchases = self::reads()->purchasesOf(self::scope($customerId), $productId);
        self::assertNotEmpty($purchases, 'The product path must resolve against real DAL associations.');
        self::assertSame('USD', $purchases[0]->currencyIso);
        self::assertSame(substr($orders[0]['orderedAt'], 0, 10), $purchases[0]->orderedAt?->format('Y-m-d'));
        self::assertGreaterThan(0, $purchases[0]->quantity);
    }

    public function testQuoteDetailCarriesItsOwnCurrencyAssociation(): void
    {
        $container = static::getContainer();
        $connection = self::connection($container);
        $customerId = self::customerWithOrders();
        $reads = new QuoteHistoryReads(
            self::repository($container, 'quote.repository'),
            new DecisionAggregate($connection),
        );
        $quotes = $reads->entries(self::scope($customerId));
        self::assertNotEmpty($quotes, 'The order-history fixture customer also needs a quote.');
        foreach ($quotes as $quote) {
            $iso = $connection->fetchOne('SELECT c.iso_code FROM quote q INNER JOIN currency c ON c.id = q.currency_id'
            . ' WHERE q.quote_number = :number AND q.customer_id = UNHEX(:customer) AND q.version_id = UNHEX(:live)', [
                'number' => $quote->quoteNumber,
                'customer' => $customerId,
                'live' => Defaults::LIVE_VERSION,
            ]);
            self::assertIsString($iso);
            self::assertSame($iso, $quote->currencyIso);
        }
    }

    private static function customerWithOrders(): string
    {
        $id = self::connection(static::getContainer())
            ->fetchOne('SELECT LOWER(HEX(oc.customer_id)) FROM `order` o'
            . ' INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id'
            . ' WHERE o.version_id = UNHEX(:live) AND oc.customer_id IS NOT NULL'
            . ' GROUP BY oc.customer_id HAVING COUNT(*) >= 2 ORDER BY COUNT(*) DESC LIMIT 1', [
                'live' => Defaults::LIVE_VERSION,
            ]);
        self::assertIsString(
            $id,
            'Seed at least two orders for one customer before running history integration tests.',
        );
        return $id;
    }

    /** @return list<array{id: string, amountNet: float, orderedAt: string}> */
    private static function orderRows(string $customerId): array
    {
        $rows = self::connection(static::getContainer())
            ->fetchAllAssociative('SELECT LOWER(HEX(o.id)) AS id, o.amount_net, o.order_date_time FROM `order` o'
            . ' INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id'
            . ' WHERE o.version_id = UNHEX(:live) AND oc.customer_id = UNHEX(:customer) ORDER BY o.order_date_time DESC', [
                'live' => Defaults::LIVE_VERSION,
                'customer' => $customerId,
            ]);
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'amountNet' => (float) $row['amount_net'],
            'orderedAt' => (string) $row['order_date_time'],
        ], $rows);
    }

    private static function currencyId(string $iso): string
    {
        $id = self::connection(static::getContainer())
            ->fetchOne('SELECT LOWER(HEX(id)) FROM currency WHERE iso_code = :iso LIMIT 1', ['iso' => $iso]);
        self::assertIsString($id, 'The development shop needs EUR and USD currencies.');
        return $id;
    }

    /** @param list<array{id: string, amountNet: float, orderedAt: string}> $orders */
    private static function setCurrency(array $orders, string $currencyId): void
    {
        $context = AgentContext::create();
        $context->addState(Context::SKIP_TRIGGER_FLOW);
        self::repository(static::getContainer(), 'order.repository')
            ->update(array_map(static fn(array $order): array => [
                'id' => $order['id'],
                'versionId' => Defaults::LIVE_VERSION,
                'currencyId' => $currencyId,
            ], $orders), $context);
    }

    private static function reads(): OrderHistoryReads
    {
        $container = static::getContainer();
        return new OrderHistoryReads(
            self::repository($container, 'order.repository'),
            self::repository($container, 'order_line_item.repository'),
        );
    }

    private static function scope(string $customerId): CustomerScope
    {
        return new CustomerScope($customerId, '', new QuoteVersionResolver());
    }
}
