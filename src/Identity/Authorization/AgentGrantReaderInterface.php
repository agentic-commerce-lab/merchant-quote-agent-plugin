<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

/**
 * Reading and revoking the grants customers have given agents.
 *
 * An interface because the implementation reads Agentic Commerce's OAuth tables
 * directly, exactly as AcOAuthAccessTokenReader does. Issue #13 adds a read side
 * to that store upstream; when it lands both implementations become delegates
 * and nothing else moves.
 */
interface AgentGrantReaderInterface
{
    /**
     * @return list<array{customer_id: string, client_id: string, scope: string, expires_at: int, revoked: bool}>
     */
    public function grantsFor(?string $customerId): array;

    /** @return int the number of refresh-token rows marked revoked */
    public function revoke(string $customerId, string $clientId): int;
}
