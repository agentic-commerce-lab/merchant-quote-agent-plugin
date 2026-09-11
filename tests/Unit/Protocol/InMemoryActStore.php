<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use Override;

/** The mirror, in memory. Idempotent on (session, sequence, role), like the real one. */
final class InMemoryActStore implements ActStoreInterface
{
    /** @var array<string, Act> keyed by "session:sequence:role" */
    public array $acts = [];

    /** @var list<ProtocolViolation> */
    public array $violations = [];

    /** @var list<ApprovalReceipt> */
    public array $receipts = [];

    /**
     * Bookkeeping for appendReceipt()'s idempotency only — not part of the
     * double's public shape, which tests read through `$receipts`.
     *
     * @var array<string, true> keyed by "sessionId:offerHash"
     */
    private array $receiptKeys = [];

    /** @var array<string, string> */
    public array $quotes = [];

    #[Override]
    public function append(ActRecord $record): void
    {
        $this->acts[self::key($record->sessionId, $record->sequence, $record->role)] = $record->act;
        $this->quotes[$record->sessionId] = $record->quoteId;
    }

    private static function key(string $sessionId, int $sequence, ActRole $role): string
    {
        return $sessionId . ':' . $sequence . ':' . $role->value;
    }

    #[Override]
    public function appendViolation(string $sessionId, ProtocolViolation $violation): void
    {
        $this->violations[] = $violation;
    }

    /**
     * Idempotent on (session, offer hash), matching ReceiptTableStore's
     * `ON DUPLICATE KEY UPDATE session_id = session_id` — a no-op update, so
     * the FIRST receipt for a key wins and a repeat append is dropped.
     */
    #[Override]
    public function appendReceipt(string $sessionId, ApprovalReceipt $receipt): void
    {
        $key = $sessionId . ':' . $receipt->offerHash;
        if (isset($this->receiptKeys[$key])) {
            return;
        }

        $this->receiptKeys[$key] = true;
        $this->receipts[] = $receipt;
    }

    /** @return list<Act> */
    #[Override]
    public function listBySession(string $sessionId): array
    {
        $acts = [];
        foreach ($this->acts as $key => $act) {
            if (str_starts_with($key, $sessionId . ':')) {
                // Sequence THEN role, exactly like ActTableStore's `ORDER BY
                // sequence, role` — a zero-padded sequence plus the role
                // character makes a plain string sort produce it, and two acts
                // at one sequence must come back in wire order (b before s).
                $acts[str_pad((string) $act->sequenceNumber(), 4, '0', \STR_PAD_LEFT) . substr($key, -1)] = $act;
            }
        }

        ksort($acts);

        return array_values($acts);
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

    /** Seeds the mirror directly, without an append(), for tests that only need the session→quote lookup. */
    public function seedSession(string $sessionId, string $quoteId): void
    {
        $this->quotes[$sessionId] = $quoteId;
    }
}
