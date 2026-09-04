<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;

/**
 * The receipt half of the mirror: `merchant_quote_agent_a2cn_receipt`.
 *
 * Split out of DbalActStore for the same reason as ActTableStore: the seam
 * is the table.
 */
final readonly class ReceiptTableStore
{
    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    public function append(string $sessionId, ApprovalReceipt $receipt): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO `merchant_quote_agent_a2cn_receipt` (`session_id`, `offer_hash`, `receipt`, `created_at`)
                VALUES (:session_id, :offer_hash, :receipt, :created_at)
                ON DUPLICATE KEY UPDATE `session_id` = `session_id`
                SQL,
            [
                'session_id' => $sessionId,
                'offer_hash' => $receipt->offerHash,
                'receipt' => json_encode($receipt->toArray(), \JSON_THROW_ON_ERROR),
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        );
    }

    /**
     * @return list<ApprovalReceipt>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function listBySession(string $sessionId): array
    {
        $rows = $this->connection->fetchFirstColumn('SELECT `receipt` FROM `merchant_quote_agent_a2cn_receipt` WHERE `session_id` = :session_id ORDER BY `created_at` ASC', [
            'session_id' => $sessionId,
        ]);

        $receipts = [];
        foreach ($rows as $row) {
            $receipt = \is_string($row) ? ApprovalReceipt::fromArray(RowDecoder::decode($row)) : null;
            if ($receipt !== null) {
                $receipts[] = $receipt;
            }
        }

        return $receipts;
    }
}
