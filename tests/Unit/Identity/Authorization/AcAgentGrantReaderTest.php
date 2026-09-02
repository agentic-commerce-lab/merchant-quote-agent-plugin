<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\Authorization\AcAgentGrantReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Both queries must read the refresh-token table and nothing else.
 *
 * That is asserted negatively as well as positively, and the negative half is
 * the point: reading the ACCESS-token table (as this class first did, joining
 * refresh onto it) makes the command blind to every grant older than one
 * access-token lifetime, because AC prunes each token table on its own
 * `expires_at` and refresh tokens outlive access tokens. Listing then says
 * "No grants." and revoking says "Revoked 0 grant(s)" while the agent still
 * holds a live refresh token. So each test also asserts the access-token
 * table is absent from the SQL — a regression back to it fails here rather
 * than in production a shift later.
 */
#[CoversClass(AcAgentGrantReader::class)]
final class AcAgentGrantReaderTest extends TestCase
{
    private const REFRESH_TABLE = 'swag_agentic_commerce_ucp_oauth_refresh_token';

    private const ACCESS_TABLE = 'swag_agentic_commerce_ucp_oauth_access_token';

    public function testItListsGrantsFromTheRefreshTokenTableAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::callback(
                static fn(string $sql): bool => (
                    str_contains($sql, self::REFRESH_TABLE)
                    && !str_contains($sql, self::ACCESS_TABLE)
                    && !str_contains($sql, 'JOIN')
                ),
            ))
            ->willReturn([[
                'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
                'client_id' => 'https://agent.example/.well-known/ucp',
                'scope' => 'dev.ucp.shopping.order:read',
                'expires_at' => 1788300000,
                'revoked_at' => null,
            ]]);

        $grants = (new AcAgentGrantReader($connection))->grantsFor(null);

        self::assertCount(1, $grants);
        self::assertFalse($grants[0]['revoked']);
        self::assertSame('https://agent.example/.well-known/ucp', $grants[0]['client_id']);
        self::assertSame('0191d3d0a0b071bd9c1a0d9d1a3f9f02', $grants[0]['customer_id']);
        self::assertSame(1788300000, $grants[0]['expires_at']);
    }

    /** A live refresh token with no access row left is the steady state, and must still be listed. */
    public function testARevokedRowIsReportedAsRevoked(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([[
                'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
                'client_id' => 'https://agent.example/.well-known/ucp',
                'scope' => '',
                'expires_at' => 1788300000,
                'revoked_at' => '2026-09-01 10:00:00',
            ]]);

        $grants = (new AcAgentGrantReader($connection))->grantsFor('0191d3d0a0b071bd9c1a0d9d1a3f9f02');

        self::assertTrue($grants[0]['revoked']);
    }

    public function testFilteringByCustomerConstrainsTheRefreshTablesOwnSubjectColumn(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(
                    static fn(string $sql): bool => (
                        str_contains($sql, self::REFRESH_TABLE)
                        && !str_contains($sql, self::ACCESS_TABLE)
                        && str_contains($sql, '`subject` = :subject')
                    ),
                ),
                self::identicalTo(['subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02']),
            )
            ->willReturn([]);

        self::assertSame([], (new AcAgentGrantReader($connection))->grantsFor('0191d3d0a0b071bd9c1a0d9d1a3f9f02'));
    }

    public function testRevokeStampsTheRefreshTokenRowWithoutTouchingTheAccessTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::callback(
                    static fn(string $sql): bool => (
                        str_contains($sql, 'UPDATE')
                        && str_contains($sql, self::REFRESH_TABLE)
                        && !str_contains($sql, self::ACCESS_TABLE)
                        && !str_contains($sql, 'JOIN')
                        && str_contains($sql, '`revoked_at` = NOW(3)')
                        && str_contains($sql, '`revoked_at` IS NULL')
                    ),
                ),
                self::identicalTo([
                    'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
                    'clientId' => 'https://agent.example/.well-known/ucp',
                ]),
            )
            ->willReturn(2);

        $revoked = (new AcAgentGrantReader($connection))->revoke(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
            'https://agent.example/.well-known/ucp',
        );

        self::assertSame(2, $revoked);
    }
}
