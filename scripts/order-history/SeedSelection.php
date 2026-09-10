<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Scripts\OrderHistory;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Policy\Data\RequiredShape;
use Shopware\Core\Defaults;

/** Selects real live quote customers and a safe shared simple product. */
final readonly class SeedSelection
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @return list<array{id: string, channel: string, host: string}>
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    public function customers(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT DISTINCT LOWER(HEX(c.id)) AS id,'
        . ' LOWER(HEX(c.sales_channel_id)) AS channel, (SELECT d.url FROM sales_channel_domain d'
        . ' WHERE d.sales_channel_id = c.sales_channel_id ORDER BY CHAR_LENGTH(d.url), d.url LIMIT 1) AS url'
        . ' FROM customer c INNER JOIN quote q ON q.customer_id = c.id AND q.version_id = UNHEX(:live)'
        . ' ORDER BY id', ['live' => Defaults::LIVE_VERSION]);
        $customers = [];
        foreach ($rows as $row) {
            $id = RequiredShape::string($row, 'id');
            $host = parse_url(RequiredShape::string($row, 'url'), PHP_URL_HOST);
            if (!is_string($host)) {
                throw new \RuntimeException('Quote customer ' . $id . ' has no sales-channel domain.');
            }
            $customers[] = ['id' => $id, 'channel' => RequiredShape::string($row, 'channel'), 'host' => $host];
        }
        if ($customers === []) {
            throw new \RuntimeException('No live quote customers found; seed merchant quote fixtures first.');
        }
        return $customers;
    }

    /**
     * @param list<array{id: string, channel: string, host: string}> $customers
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    public function product(array $customers, int $newSlots, ?string $requested): string
    {
        $requiredStock = $newSlots * 4;
        $rows = $this->connection->fetchAllAssociative('SELECT LOWER(HEX(p.id)) AS id FROM product p'
        . ' WHERE p.version_id = UNHEX(:live) AND p.active = 1 AND p.parent_id IS NULL'
        . ' AND COALESCE(p.child_count, 0) = 0 AND p.is_closeout = 0 AND p.stock >= :stock'
        . ' AND COALESCE(p.min_purchase, 1) <= 1 AND COALESCE(p.purchase_steps, 1) = 1'
        . ' AND (p.max_purchase IS NULL OR p.max_purchase >= 4)'
        . ' AND (:requested IS NULL OR p.id = UNHEX(:requested)) ORDER BY p.product_number', [
            'live' => Defaults::LIVE_VERSION,
            'stock' => $requiredStock,
            'requested' => $requested,
        ]);
        foreach ($rows as $row) {
            $id = RequiredShape::string($row, 'id');
            if ($this->visibleInEveryChannel($id, $customers)) {
                return $id;
            }
        }
        throw new \RuntimeException(
            'No active, stocked, simple product visible in every customer channel; check --product-id.',
        );
    }

    /**
     * @param list<array{id: string, channel: string, host: string}> $customers
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    private function visibleInEveryChannel(string $productId, array $customers): bool
    {
        foreach ($customers as $customer) {
            $visible = $this->connection->fetchOne('SELECT 1 FROM product_visibility'
            . ' WHERE product_id = UNHEX(:product) AND product_version_id = UNHEX(:live)'
            . ' AND sales_channel_id = UNHEX(:channel) AND visibility > 0 LIMIT 1', [
                'product' => $productId,
                'live' => Defaults::LIVE_VERSION,
                'channel' => $customer['channel'],
            ]);
            if ($visible === false) {
                return false;
            }
        }
        return true;
    }
}
