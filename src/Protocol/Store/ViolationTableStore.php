<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The violation half of the mirror: `merchant_quote_agent_a2cn_violation`.
 *
 * Split out of DbalActStore for the same reason as ActTableStore: the seam
 * is the table.
 */
final readonly class ViolationTableStore
{
    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    public function append(string $sessionId, ProtocolViolation $violation): void
    {
        $this->connection->insert('merchant_quote_agent_a2cn_violation', [
            'id' => Uuid::randomBytes(),
            'session_id' => $sessionId,
            'violation' => json_encode($violation->toArray(), \JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }

    /**
     * @return list<ProtocolViolation>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function listBySession(string $sessionId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT `violation` FROM `merchant_quote_agent_a2cn_violation` WHERE `session_id` = :session_id ORDER BY `created_at` ASC',
            ['session_id' => $sessionId],
        );

        $violations = [];
        foreach ($rows as $row) {
            $violation = \is_string($row) ? ProtocolViolation::fromArray(RowDecoder::decode($row)) : null;
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }
}
