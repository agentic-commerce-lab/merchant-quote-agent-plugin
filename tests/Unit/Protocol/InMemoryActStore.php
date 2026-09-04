<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use Override;

/** The mirror, in memory. Idempotent on (session, sequence), like the real one. */
final class InMemoryActStore implements ActStoreInterface
{
    /** @var array<string, Act> keyed by "session:sequence" */
    public array $acts = [];

    /** @var list<ProtocolViolation> */
    public array $violations = [];

    /** @var list<ApprovalReceipt> */
    public array $receipts = [];

    /** @var array<string, string> */
    public array $quotes = [];

    #[Override]
    public function append(ActRecord $record): void
    {
        $this->acts[$record->sessionId . ':' . $record->sequence] = $record->act;
        $this->quotes[$record->sessionId] = $record->quoteId;
    }

    #[Override]
    public function appendViolation(string $sessionId, ProtocolViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    #[Override]
    public function appendReceipt(string $sessionId, ApprovalReceipt $receipt): void
    {
        $this->receipts[] = $receipt;
    }

    /** @return list<Act> */
    #[Override]
    public function listBySession(string $sessionId): array
    {
        $acts = [];
        foreach ($this->acts as $key => $act) {
            if (str_starts_with($key, $sessionId . ':')) {
                $acts[] = $act;
            }
        }

        usort($acts, static fn(Act $a, Act $b): int => $a->sequenceNumber() <=> $b->sequenceNumber());

        return $acts;
    }

    /** @return list<ProtocolViolation> */
    #[Override]
    public function listViolations(string $sessionId): array
    {
        return $this->violations;
    }

    /** @return list<ApprovalReceipt> */
    #[Override]
    public function listReceipts(string $sessionId): array
    {
        return $this->receipts;
    }

    #[Override]
    public function quoteIdForSession(string $sessionId): ?string
    {
        return $this->quotes[$sessionId] ?? null;
    }
}
