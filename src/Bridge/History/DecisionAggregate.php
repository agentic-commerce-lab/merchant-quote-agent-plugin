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
 * There is NO customer filter here, and adding one is impossible:
 * merchant_quote_agent_decision is keyed by quote_id and has no customer
 * column. The scope comes from the ids instead, and they are the ids a
 * customer-filtered quote read returned — so this query structurally cannot
 * reach a quote that read did not. That indirection is the security property,
 * not a workaround for the missing column.
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

        /** @var list<array{quote_id: string, authorized: int|null, discount_percent_granted: float|null, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(`quote_id`)) AS `quote_id`, `authorized`, `discount_percent_granted`, `created_at`'
            . ' FROM `merchant_quote_agent_decision` WHERE `quote_id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($quoteIds)],
            ['ids' => ArrayParameterType::BINARY],
        );

        return DecisionRollup::of($rows);
    }
}
