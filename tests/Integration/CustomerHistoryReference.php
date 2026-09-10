<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/** Independent SQL oracle: never calls the history readers or their price helpers. */
final readonly class CustomerHistoryReference
{
    public function __construct(
        private Connection $connection,
    ) {}

    /** @return list<array{number: string, net: float, currency: ?string, date: string}> */
    public function orders(string $customerId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT o.order_number, o.amount_net, c.iso_code, o.order_date_time'
        . ' FROM `order` o INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id'
        . ' LEFT JOIN currency c ON c.id = o.currency_id'
        . ' WHERE o.version_id = UNHEX(:live) AND oc.customer_id = UNHEX(:customer)'
        . ' ORDER BY o.order_date_time DESC', ['live' => Defaults::LIVE_VERSION, 'customer' => $customerId]);

        return array_map(static fn(array $row): array => [
            'number' => (string) $row['order_number'],
            'net' => (float) $row['amount_net'],
            'currency' => $row['iso_code'] === null ? null : (string) $row['iso_code'],
            'date' => substr((string) $row['order_date_time'], offset: 0, length: 19),
        ], $rows);
    }

    /** @return list<string> products independently known to occur in both companies' live orders */
    public function sharedProducts(string $first, string $second): array
    {
        $ids = $this->connection->fetchFirstColumn('SELECT LOWER(HEX(li.product_id)) FROM order_line_item li'
        . ' INNER JOIN `order` o ON o.id = li.order_id AND o.version_id = li.order_version_id'
        . ' INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id'
        . ' WHERE o.version_id = UNHEX(:live) AND li.version_id = UNHEX(:live) AND li.product_id IS NOT NULL'
        . ' AND oc.customer_id IN (UNHEX(:first), UNHEX(:second))'
        . ' GROUP BY li.product_id HAVING COUNT(DISTINCT oc.customer_id) = 2', [
            'live' => Defaults::LIVE_VERSION,
            'first' => $first,
            'second' => $second,
        ]);

        return array_map(static fn($id): string => (string) $id, $ids);
    }

    /** @return list<array{quantity: int, net: float, currency: ?string, date: string}> */
    public function purchases(string $customerId, string $productId): array
    {
        // Shopware stores calculated line taxes in JSON. Subtract them only
        // for gross orders, round the line net first, then derive net per unit.
        // DECIMAL avoids MySQL DOUBLE round-to-even at half-cent boundaries;
        // PHP money rounding uses half-up (33.61 / 2 must become 16.81).
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT li.quantity, c.iso_code, o.order_date_time,
                    ROUND((CASE WHEN o.tax_status = 'gross' THEN
                        ROUND(CAST(JSON_VALUE(li.price, '$.totalPrice') AS DECIMAL(20, 8)) - COALESCE((
                            SELECT SUM(t.tax) FROM JSON_TABLE(li.price, '$.calculatedTaxes[*]'
                                COLUMNS (tax DECIMAL(20, 8) PATH '$.tax')) t
                        ), 0), 2)
                    ELSE ROUND(CAST(JSON_VALUE(li.price, '$.totalPrice') AS DECIMAL(20, 8)), 2) END) / GREATEST(li.quantity, 1), 2) AS unit_net
                FROM order_line_item li
                INNER JOIN `order` o ON o.id = li.order_id AND o.version_id = li.order_version_id
                INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id
                LEFT JOIN currency c ON c.id = o.currency_id
                WHERE o.version_id = UNHEX(:live) AND li.version_id = UNHEX(:live)
                    AND oc.customer_id = UNHEX(:customer) AND li.product_id = UNHEX(:product)
                ORDER BY o.order_date_time DESC
                LIMIT 10
                SQL,
            ['live' => Defaults::LIVE_VERSION, 'customer' => $customerId, 'product' => $productId],
        );

        return array_map(static fn(array $row): array => [
            'quantity' => (int) $row['quantity'],
            'net' => (float) $row['unit_net'],
            'currency' => $row['iso_code'] === null ? null : (string) $row['iso_code'],
            'date' => substr((string) $row['order_date_time'], offset: 0, length: 19),
        ], $rows);
    }
}
