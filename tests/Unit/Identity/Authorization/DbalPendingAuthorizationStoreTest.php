<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\Authorization\DbalPendingAuthorizationStore;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DbalPendingAuthorizationStore::class)]
#[CoversClass(PendingAuthorization::class)]
final class DbalPendingAuthorizationStoreTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private function pending(): PendingAuthorization
    {
        return new PendingAuthorization(
            self::SALES_CHANNEL_ID,
            'https://agent.example/.well-known/ucp',
            ['ucp' => ['version' => '2026-04-08']],
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );
    }

    public function testItStoresOnlyTheHandleHashAndReturnsThePlaintextHandle(): void
    {
        $captured = [];
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$captured): int {
                $captured[] = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        $store = new DbalPendingAuthorizationStore($connection);
        $handle = $store->store($this->pending(), 600);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $handle);

        $insert = array_values(array_filter($captured, static fn(array $call): bool => str_contains(
            $call['sql'],
            'INSERT INTO',
        )));
        self::assertCount(1, $insert);
        self::assertSame(hash('sha256', $handle, true), $insert[0]['params']['handleHash']);
        self::assertStringNotContainsString($handle, $insert[0]['sql']);
        foreach ($insert[0]['params'] as $value) {
            self::assertNotSame($handle, $value, 'the plaintext handle must never be persisted');
        }
    }

    public function testItFindsByHandleHashAndRehydratesTheProfile(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(
                    static fn(string $sql): bool => (
                        str_contains($sql, 'merchant_quote_agent_pending_authorization')
                        && str_contains($sql, 'consumed_at IS NULL')
                        && str_contains($sql, 'expires_at')
                    ),
                ),
                self::callback(
                    static fn(array $params): bool => $params['handleHash'] === hash('sha256', 'plain-handle', true),
                ),
            )
            ->willReturn([
                'sales_channel_id' => self::SALES_CHANNEL_ID,
                'client_id' => 'https://agent.example/.well-known/ucp',
                'agent_profile' => '{"ucp":{"version":"2026-04-08"}}',
                'redirect_uri' => 'https://agent.example/callback',
                'scope' => 'dev.ucp.shopping.order:read',
                'state' => 'state-value',
                'code_challenge' => 'challenge-value',
                'code_challenge_method' => 'S256',
            ]);

        $found = (new DbalPendingAuthorizationStore($connection))->find('plain-handle');

        self::assertNotNull($found);
        self::assertSame(self::SALES_CHANNEL_ID, $found->salesChannelId);
        self::assertSame(['ucp' => ['version' => '2026-04-08']], $found->agentProfile);
        self::assertSame('S256', $found->codeChallengeMethod);
    }

    public function testFindReturnsNullWhenNoRowMatches(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull((new DbalPendingAuthorizationStore($connection))->find('missing'));
    }

    public function testConsumeReturnsNullWhenTheConditionalUpdateAffectsNoRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn([
                'sales_channel_id' => self::SALES_CHANNEL_ID,
                'client_id' => 'https://agent.example/.well-known/ucp',
                'agent_profile' => '{}',
                'redirect_uri' => 'https://agent.example/callback',
                'scope' => '',
                'state' => 'state-value',
                'code_challenge' => 'challenge-value',
                'code_challenge_method' => 'S256',
            ]);
        // The conditional UPDATE is what makes consumption single-use: a second
        // caller updates zero rows and must get nothing back.
        $connection->method('executeStatement')->willReturn(0);

        self::assertNull((new DbalPendingAuthorizationStore($connection))->consume('plain-handle'));
    }
}
