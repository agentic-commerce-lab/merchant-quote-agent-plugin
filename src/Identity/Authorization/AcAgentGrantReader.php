<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Doctrine\DBAL\Connection;
use Override;

/**
 * The coupling to Agentic Commerce's OAuth storage, second instance.
 *
 * Revocation is recorded on the refresh-token row rather than the access-token
 * row — the same asymmetry AcOAuthAccessTokenReader documents and relies on, so
 * stamping it there is what actually makes an access token stop resolving.
 *
 * `subject` is read as a plain string, matching AcOAuthAccessTokenReader: AC's
 * own store writes and reads it unhexed, unlike `sales_channel_id`.
 */
final readonly class AcAgentGrantReader implements AgentGrantReaderInterface
{
    private const ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';

    private const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function grantsFor(?string $customerId): array
    {
        $sql = \sprintf(
            'SELECT a.subject, a.client_id, a.scope, a.expires_at, r.revoked_at'
            . ' FROM `%s` AS a'
            . ' LEFT JOIN `%s` AS r ON r.token_hash = a.refresh_token_hash',
            self::ACCESS_TOKEN_TABLE,
            self::REFRESH_TOKEN_TABLE,
        );
        $params = [];

        if ($customerId !== null) {
            $sql .= ' WHERE a.subject = :subject';
            $params['subject'] = $customerId;
        }

        $rows = $this->connection->fetchAllAssociative($sql . ' ORDER BY a.expires_at DESC', $params);

        return array_map(static fn(array $row): array => [
            'customer_id' => (string) $row['subject'],
            'client_id' => (string) $row['client_id'],
            'scope' => (string) $row['scope'],
            'expires_at' => (int) $row['expires_at'],
            'revoked' => $row['revoked_at'] !== null,
        ], $rows);
    }

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function revoke(string $customerId, string $clientId): int
    {
        return (int) $this->connection->executeStatement(
            \sprintf(
                'UPDATE `%s` AS r'
                . ' INNER JOIN `%s` AS a ON a.refresh_token_hash = r.token_hash'
                . ' SET r.revoked_at = NOW(3)'
                . ' WHERE a.subject = :subject AND a.client_id = :clientId AND r.revoked_at IS NULL',
                self::REFRESH_TOKEN_TABLE,
                self::ACCESS_TOKEN_TABLE,
            ),
            ['subject' => $customerId, 'clientId' => $clientId],
        );
    }
}
