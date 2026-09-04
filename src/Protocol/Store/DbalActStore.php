<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use Override;

/**
 * The mirror over three hand-written tables. Direct DBAL rather than the DAL:
 * nothing here is administrated through Shopware's admin API, and the decision
 * record and pending-authorization stores set the same precedent.
 *
 * A thin facade: the actual per-table read/write/decode logic lives in
 * ActTableStore, ViolationTableStore and ReceiptTableStore, one per table —
 * splitting on that seam is what keeps this class under the per-class
 * cyclomatic-complexity gate. The public constructor still takes only a
 * Connection, so nothing outside this file needs to know the split exists.
 *
 * Every read tolerates an unreadable row by skipping it: a records request for
 * a whole session must not fail because one row cannot be decoded.
 */
final readonly class DbalActStore implements ActStoreInterface
{
    private ActTableStore $acts;

    private ViolationTableStore $violations;

    private ReceiptTableStore $receipts;

    public function __construct(Connection $connection)
    {
        $this->acts = new ActTableStore($connection);
        $this->violations = new ViolationTableStore($connection);
        $this->receipts = new ReceiptTableStore($connection);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function append(ActRecord $record): void
    {
        $this->acts->append($record);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function appendViolation(string $sessionId, ProtocolViolation $violation): void
    {
        $this->violations->append($sessionId, $violation);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function appendReceipt(string $sessionId, ApprovalReceipt $receipt): void
    {
        $this->receipts->append($sessionId, $receipt);
    }

    /**
     * @return list<Act>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    public function listBySession(string $sessionId): array
    {
        return $this->acts->listBySession($sessionId);
    }

    /**
     * @return list<ProtocolViolation>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    public function listViolations(string $sessionId): array
    {
        return $this->violations->listBySession($sessionId);
    }

    /**
     * @return list<ApprovalReceipt>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    public function listReceipts(string $sessionId): array
    {
        return $this->receipts->listBySession($sessionId);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function quoteIdForSession(string $sessionId): ?string
    {
        return $this->acts->quoteIdForSession($sessionId);
    }
}
