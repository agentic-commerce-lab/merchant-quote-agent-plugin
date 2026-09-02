<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Doctrine\DBAL\Connection;
use Override;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Handles are hashed with raw sha256 before they touch the database, matching
 * how Agentic Commerce stores its own OAuth tokens, so a database read never
 * yields a usable handle.
 *
 * `consume()` is a conditional UPDATE rather than a read-then-write: two
 * concurrent consent submissions must not both mint an authorization code, and
 * the affected-row count is the only thing that settles that without a lock.
 */
final readonly class DbalPendingAuthorizationStore implements PendingAuthorizationStoreInterface
{
    private const TABLE = 'merchant_quote_agent_pending_authorization';

    private const HANDLE_BYTES = 32;

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \Random\RandomException
     */
    #[Override]
    public function store(PendingAuthorization $pending, int $ttlSeconds): string
    {
        $this->deleteSpent();

        $handle = self::handle();

        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO `%s` (`handle_hash`, `sales_channel_id`, `client_id`, `agent_profile`,'
                . ' `redirect_uri`, `scope`, `state`, `code_challenge`, `code_challenge_method`,'
                . ' `created_at`, `expires_at`)'
                . ' VALUES (:handleHash, :salesChannelId, :clientId, :agentProfile, :redirectUri,'
                . ' :scope, :state, :codeChallenge, :codeChallengeMethod, NOW(3), :expiresAt)',
                self::TABLE,
            ),
            [
                'handleHash' => hash('sha256', $handle, true),
                'salesChannelId' => Uuid::fromHexToBytes($pending->salesChannelId),
                'clientId' => $pending->clientId,
                'agentProfile' => json_encode($pending->agentProfile, \JSON_THROW_ON_ERROR),
                'redirectUri' => $pending->redirectUri,
                'scope' => $pending->scope,
                'state' => $pending->state,
                'codeChallenge' => $pending->codeChallenge,
                'codeChallengeMethod' => $pending->codeChallengeMethod,
                'expiresAt' => date('Y-m-d H:i:s', time() + $ttlSeconds),
            ],
        );

        return $handle;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    #[Override]
    public function find(#[\SensitiveParameter] string $handle): ?PendingAuthorization
    {
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT LOWER(HEX(`sales_channel_id`)) AS sales_channel_id, `client_id`, `agent_profile`,'
                . ' `redirect_uri`, `scope`, `state`, `code_challenge`, `code_challenge_method`'
                . ' FROM `%s`'
                . ' WHERE `handle_hash` = :handleHash AND consumed_at IS NULL AND `expires_at` > NOW(3)',
                self::TABLE,
            ),
            ['handleHash' => hash('sha256', $handle, true)],
        );

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     * @throws \JsonException
     */
    #[Override]
    public function consume(#[\SensitiveParameter] string $handle): ?PendingAuthorization
    {
        $pending = $this->find($handle);

        if ($pending === null) {
            return null;
        }

        $claimed = $this->connection->executeStatement(
            \sprintf(
                'UPDATE `%s` SET consumed_at = NOW(3)'
                . ' WHERE `handle_hash` = :handleHash AND consumed_at IS NULL AND `expires_at` > NOW(3)',
                self::TABLE,
            ),
            ['handleHash' => hash('sha256', $handle, true)],
        );

        return (int) $claimed === 1 ? $pending : null;
    }

    /**
     * Rows live ten minutes, so spent ones are cleared on the next write rather
     * than by a scheduled task.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function deleteSpent(): void
    {
        $this->connection->executeStatement(\sprintf(
            'DELETE FROM `%s` WHERE `expires_at` <= NOW(3) OR consumed_at IS NOT NULL',
            self::TABLE,
        ));
    }

    /** @throws \Random\RandomException */
    private static function handle(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::HANDLE_BYTES)), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws \JsonException
     */
    private static function hydrate(array $row): PendingAuthorization
    {
        /** @var array<string, mixed> $profile */
        $profile = json_decode((string) $row['agent_profile'], true, 512, \JSON_THROW_ON_ERROR);

        return new PendingAuthorization(
            (string) $row['sales_channel_id'],
            (string) $row['client_id'],
            $profile,
            (string) $row['redirect_uri'],
            (string) $row['scope'],
            (string) $row['state'],
            (string) $row['code_challenge'],
            (string) $row['code_challenge_method'],
        );
    }
}
