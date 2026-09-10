<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\Data\History\DecisionRollup;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Our own pass records for a set of quotes.
 *
 * Scope comes from the quote ids a customer-filtered read returned, so this
 * query cannot reach a quote outside that read. The newer customer_id audit
 * attribution is intentionally not required: older records lack it and still
 * belong to their verified quote.
 *
 * DBAL rather than the DAL because the record is a plain attribute entity with
 * no association to the quote, and this is one indexed IN over `idx.mqad.quote_id`.
 */
final readonly class DecisionAggregate
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @param list<string> $quoteIds hex ids from an already customer-scoped read
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function forQuotes(array $quoteIds): DecisionRollup
    {
        if ($quoteIds === []) {
            return new DecisionRollup();
        }

        // Shopware's connection sets PDO::ATTR_STRINGIFY_FETCHES, so every scalar
        // column below arrives as a string, never as int/float/null-preserving
        // native types. Cast here, at the query boundary, so DecisionRollup::of()
        // stays a pure function over the types its signature actually promises.
        /** @var list<array{quote_id: string, authorized: string|null, discount_percent_granted: string|null, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`quote_id`)) AS `quote_id`, `authorized`, `discount_percent_granted`, `created_at`'
            . ' FROM `merchant_quote_agent_decision` WHERE `quote_id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($quoteIds)],
            ['ids' => ArrayParameterType::BINARY],
        );

        return DecisionRollup::of(array_map(static fn(array $row): array => [
            'quote_id' => (string) $row['quote_id'],
            'authorized' => $row['authorized'] === null ? null : (int) $row['authorized'],
            'discount_percent_granted' => $row['discount_percent_granted'] === null
                ? null
                : (float) $row['discount_percent_granted'],
            'created_at' => (string) $row['created_at'],
        ], $rows));
    }
}
