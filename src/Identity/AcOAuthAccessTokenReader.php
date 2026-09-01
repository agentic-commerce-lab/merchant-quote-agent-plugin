<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Doctrine\DBAL\Connection;
use Override;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Resolves a bearer token against the Agentic Commerce plugin's OAuth tables.
 *
 * This is the whole coupling to that plugin's storage: the two table names, the
 * token hash (`hash('sha256', $token, true)`, matching its own
 * DoctrineDbalUcpOAuthStore) and the fact that revocation is recorded on the
 * refresh-token row rather than the access-token row. Issue #13 upstream adds a
 * read side to that store; the day it lands, this class becomes a delegate and
 * the rest of the plugin does not move.
 *
 * `AcOAuthAccessTokenReaderTest` pins all three assumptions, and the
 * integration suite issues a token through Agentic Commerce's own writer and
 * reads it back here — that pairing is what fails loudly if it renames
 * anything.
 */
final readonly class AcOAuthAccessTokenReader implements AccessTokenSubjectReaderInterface
{
    private const ACCESS_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';

    private const REFRESH_TOKEN_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    public function find(#[\SensitiveParameter] string $accessToken, string $salesChannelId): ?OAuthAccessTokenInfo
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT LOWER(HEX(a.sales_channel_id)) AS sales_channel_id, a.client_id, a.subject, a.scope,'
                . ' a.expires_at, r.revoked_at'
                . ' FROM `%s` AS a'
                . ' LEFT JOIN `%s` AS r ON r.token_hash = a.refresh_token_hash'
                . ' WHERE a.token_hash = :tokenHash AND a.sales_channel_id = :salesChannelId',
                self::ACCESS_TOKEN_TABLE,
                self::REFRESH_TOKEN_TABLE,
            ),
            [
                'tokenHash' => hash('sha256', $accessToken, true),
                'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
            ],
        );

        if ($row === false) {
            return null;
        }

        if ((int) $row['expires_at'] < time()) {
            return null;
        }

        if ($row['revoked_at'] !== null) {
            return null;
        }

        return new OAuthAccessTokenInfo(
            (string) $row['sales_channel_id'],
            (string) $row['client_id'],
            (string) $row['subject'],
            $this->scopeList((string) $row['scope']),
        );
    }

    /**
     * @return list<string>
     */
    private function scopeList(string $scope): array
    {
        return array_values(array_filter(explode(' ', trim($scope)), static fn(string $entry): bool => $entry !== ''));
    }
}
