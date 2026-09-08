<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use Shopware\Core\Defaults;

/**
 * The act half of the mirror: `merchant_quote_agent_a2cn_act`.
 *
 * Split out of DbalActStore, which would otherwise carry all three tables'
 * write/read/decode logic in one class and trip this repo's per-class
 * cyclomatic-complexity gate — the seam is the table, exactly like the
 * violation and receipt halves.
 */
final readonly class ActTableStore
{
    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    public function append(ActRecord $record): void
    {
        // ON DUPLICATE KEY UPDATE of a column to its own value: the whole point
        // is that a re-mirror is free, not an error.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO `merchant_quote_agent_a2cn_act` (`session_id`, `quote_id`, `sequence`, `role`, `act`, `created_at`)
                VALUES (:session_id, :quote_id, :sequence, :role, :act, :created_at)
                ON DUPLICATE KEY UPDATE `session_id` = `session_id`
                SQL,
            [
                'session_id' => $record->sessionId,
                'quote_id' => $record->quoteId,
                'sequence' => $record->sequence,
                'role' => $record->role->value,
                'act' => json_encode($record->act->raw(), \JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        );
    }

    /**
     * @return list<Act>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function listBySession(string $sessionId): array
    {
        // Sequence THEN role, which is the wire chain's own order: the keys
        // sort lexically, and `a2cn_act_0002_b` precedes `a2cn_act_0002_s`.
        // A records response and an offer_chain_hash computed from the mirror
        // have to see the acts in the same order as one computed from the
        // quote, or the two hashes disagree over nothing but ordering.
        $rows = $this->connection->fetchFirstColumn('SELECT `act` FROM `merchant_quote_agent_a2cn_act` WHERE `session_id` = :session_id ORDER BY `sequence` ASC, `role` ASC', [
            'session_id' => $sessionId,
        ]);

        $acts = [];
        foreach ($rows as $row) {
            $act = \is_string($row) ? Act::fromArray(RowDecoder::decode($row)) : null;
            if ($act !== null) {
                $acts[] = $act;
            }
        }

        return $acts;
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function quoteIdForSession(string $sessionId): ?string
    {
        // UUIDv5 is not reversible, so the mirror is the only way back from a
        // session id to its quote — which is what the records endpoints need.
        $quoteId = $this->connection->fetchOne('SELECT `quote_id` FROM `merchant_quote_agent_a2cn_act` WHERE `session_id` = :session_id LIMIT 1', [
            'session_id' => $sessionId,
        ]);

        return \is_string($quoteId) && $quoteId !== '' ? $quoteId : null;
    }
}
