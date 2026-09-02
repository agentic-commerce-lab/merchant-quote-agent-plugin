<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

/**
 * Short-lived storage for authorization requests awaiting a human answer.
 *
 * The handle the caller receives is a bearer secret: only its hash is stored,
 * and `consume()` is single-use so one registration can mint at most one code.
 */
interface PendingAuthorizationStoreInterface
{
    /** @return string the plaintext handle; only its hash is persisted */
    public function store(PendingAuthorization $pending, int $ttlSeconds): string;

    public function find(string $handle): ?PendingAuthorization;

    /** Atomically marks the record used. Null when unknown, expired or already consumed. */
    public function consume(string $handle): ?PendingAuthorization;
}
