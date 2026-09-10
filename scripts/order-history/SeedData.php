<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Scripts\OrderHistory;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Policy\Data\OptionalShape;
use MerchantQuoteAgentPlugin\Policy\Data\RequiredShape;
use Shopware\Core\Defaults;

/** Read-only fixture selection, recovery and currency-aware reporting. */
final readonly class SeedData
{
    public const MARKER_FIELD = 'merchant_quote_agent_order_history';

    private const INITIAL_SLOT_DAYS = [517, 345, 173, 1];

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    public function databaseName(): string
    {
        $row = $this->connection->fetchAssociative('SELECT DATABASE() AS name');
        if ($row === false) {
            throw new \RuntimeException('The active connection has no database selected.');
        }
        return RequiredShape::string($row, 'name');
    }

    /** @throws \DateInvalidTimeZoneException */
    public static function date(int $slot, int $customerIndex): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $days = self::INITIAL_SLOT_DAYS[$slot - 1] ?? (self::INITIAL_SLOT_DAYS[0] - (24 * ($slot - 4)));
        // Different timestamps make same-SKU cross-company assertions observable.
        return $now->modify('-' . $days . ' days')->modify('+' . $customerIndex . ' seconds');
    }

    public static function marker(string $customerId, int $slot): string
    {
        return 'merchant-quote-agent:order-history:v1:' . $customerId . ':' . $slot;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    public function order(string $marker, string $customerId): ?string
    {
        $id = $this->connection->fetchOne('SELECT LOWER(HEX(o.id)) FROM `order` o'
        . ' INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id'
        . ' WHERE o.version_id = UNHEX(:live) AND oc.customer_id = UNHEX(:customer)'
        . ' AND o.customer_comment = :marker LIMIT 1', [
            'live' => Defaults::LIVE_VERSION,
            'customer' => $customerId,
            'marker' => $marker,
        ]);
        return is_string($id) ? $id : null;
    }

    /**
     * @return array{id: string, state: string, date: string}|null
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    public function quote(string $marker, string $customerId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT LOWER(HEX(q.id)) AS id, s.technical_name AS state,'
        . ' JSON_UNQUOTE(JSON_EXTRACT(q.custom_fields, :datePath)) AS date FROM quote q'
        . ' INNER JOIN state_machine_state s ON s.id = q.state_id'
        . ' WHERE q.version_id = UNHEX(:live) AND q.customer_id = UNHEX(:customer)'
        . ' AND JSON_UNQUOTE(JSON_EXTRACT(q.custom_fields, :markerPath)) = :marker LIMIT 1', [
            'live' => Defaults::LIVE_VERSION,
            'customer' => $customerId,
            'marker' => $marker,
            'markerPath' => '$.' . self::MARKER_FIELD,
            'datePath' => '$.' . self::MARKER_FIELD . '_date',
        ]);
        if ($row === false) {
            return null;
        }
        return [
            'id' => RequiredShape::string($row, 'id'),
            'state' => RequiredShape::string($row, 'state'),
            'date' => RequiredShape::string($row, 'date'),
        ];
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     */
    public function report(string $customerId, int $created, int $skipped): void
    {
        // Separate rows per original currency: never add EUR and USD amounts.
        $rows = $this->connection->fetchAllAssociative('SELECT CAST(COUNT(*) AS CHAR) AS n, CAST(SUM(o.amount_net) AS CHAR) AS net,'
        . ' c.iso_code, MAX(o.order_date_time) AS latest FROM `order` o'
        . ' INNER JOIN order_customer oc ON oc.order_id = o.id AND oc.order_version_id = o.version_id'
        . ' LEFT JOIN currency c ON c.id = o.currency_id'
        . ' WHERE o.version_id = UNHEX(:live) AND oc.customer_id = UNHEX(:customer)'
        . ' GROUP BY c.iso_code ORDER BY c.iso_code', ['live' => Defaults::LIVE_VERSION, 'customer' => $customerId]);
        fwrite(STDOUT, sprintf("customer=%s created=%d skipped=%d\n", $customerId, $created, $skipped));
        foreach ($rows as $row) {
            $currency = OptionalShape::string($row, 'iso_code');
            $amount = RequiredShape::string($row, 'net');
            if (!is_numeric($amount)) {
                throw new \RuntimeException('Expected a numeric order-history net amount.');
            }
            $net = $currency === null ? 'unavailable' : number_format((float) $amount, 2, '.', '');
            fwrite(STDOUT, sprintf(
                "  count=%d net=%s currency=%s latest=%s\n",
                RequiredShape::string($row, 'n'),
                $net,
                $currency ?? 'unknown',
                RequiredShape::string($row, 'latest'),
            ));
        }
    }
}
