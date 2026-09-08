<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;

/**
 * The evidence mirror.
 *
 * Shopware is custodian of the authoritative act chain, which means it could in
 * principle omit an act. This copy is what lets us show the omission, and it is
 * what the records endpoints read so a records request never depends on a live
 * quote round trip.
 */
interface ActStoreInterface
{
    /** Idempotent on (session, sequence, role) — see ActRecord for why the role is part of it. */
    public function append(ActRecord $record): void;

    public function appendViolation(string $sessionId, ProtocolViolation $violation): void;

    /** Idempotent on (session, offer hash). */
    public function appendReceipt(string $sessionId, ApprovalReceipt $receipt): void;

    /** @return list<Act> in sequence order */
    public function listBySession(string $sessionId): array;

    /** @return list<ProtocolViolation> */
    public function listViolations(string $sessionId): array;

    /** @return list<ApprovalReceipt> */
    public function listReceipts(string $sessionId): array;

    public function quoteIdForSession(string $sessionId): ?string;
}
