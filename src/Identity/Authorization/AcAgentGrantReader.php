<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Doctrine\DBAL\Connection;
use Override;

/**
 * The coupling to Agentic Commerce's OAuth storage, second instance.
 *
 * Both queries read the REFRESH-token table, and neither joins. That is the
 * whole point: the refresh token IS the grant.
 *
 * Reading the access-token table instead makes the command useless within an
 * hour of a grant. AC's `DoctrineDbalUcpOAuthStore::deleteExpiredTokens()`
 * prunes all three token tables, each against its own `expires_at`, and
 * refresh tokens outlive access tokens — so the steady state for any grant
 * older than one access-token lifetime is a live refresh row with NO access
 * row. A listing driven off the access table prints "No grants." for exactly
 * the grants a merchant is trying to revoke, and a revoke joined through it
 * reports "Revoked 0 grant(s)" while the agent keeps minting fresh access
 * tokens from its still-live refresh token. Revocation being *possible* is
 * what the design traded a storefront page away for; that trade only holds
 * if this reads the row that actually confers the grant.
 *
 * Stamping `revoked_at` on the refresh row is also what makes an access token
 * stop resolving — the same asymmetry AcOAuthAccessTokenReader documents and
 * relies on.
 *
 * `revoked` therefore reports each refresh row's own state. AC stamps
 * `revoked_at` on the old row every time it rotates a refresh token, so a
 * customer who has used a grant for a while has several revoked rows and one
 * live one, until the expired ones are pruned. That is the truth about the
 * table; the previous join misattributed a rotated row's `revoked_at` to a
 * live access token and reported a working grant as revoked.
 *
 * `subject` is read as a plain string, matching AcOAuthAccessTokenReader: AC's
 * own store writes and reads it unhexed, unlike `sales_channel_id`.
 */
final readonly class AcAgentGrantReader implements AgentGrantReaderInterface
{
    private const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    public function __construct(
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Override]
    public function grantsFor(?string $customerId): array
    {
        $sql = \sprintf(
            'SELECT `subject`, `client_id`, `scope`, `expires_at`, `revoked_at` FROM `%s`',
            self::REFRESH_TOKEN_TABLE,
        );
        $params = [];

        if ($customerId !== null) {
            $sql .= ' WHERE `subject` = :subject';
            $params['subject'] = $customerId;
        }

        $rows = $this->connection->fetchAllAssociative($sql . ' ORDER BY `expires_at` DESC', $params);

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
                'UPDATE `%s` SET `revoked_at` = NOW(3)'
                . ' WHERE `subject` = :subject AND `client_id` = :clientId AND `revoked_at` IS NULL',
                self::REFRESH_TOKEN_TABLE,
            ),
            ['subject' => $customerId, 'clientId' => $clientId],
        );
    }
}
