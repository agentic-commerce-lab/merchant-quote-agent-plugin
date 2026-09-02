<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\Authorization\AcAgentGrantReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcAgentGrantReader::class)]
final class AcAgentGrantReaderTest extends TestCase
{
    public function testItReadsAgenticCommerceOwnTablesAndReportsRevocation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(self::callback(
                static fn(string $sql): bool => (
                    str_contains($sql, 'swag_agentic_commerce_ucp_oauth_access_token')
                    && str_contains($sql, 'swag_agentic_commerce_ucp_oauth_refresh_token')
                ),
            ))
            ->willReturn([[
                'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
                'client_id' => 'https://agent.example/.well-known/ucp',
                'scope' => 'dev.ucp.shopping.order:read',
                'expires_at' => 1788300000,
                'revoked_at' => '2026-09-01 10:00:00',
            ]]);

        $grants = (new AcAgentGrantReader($connection))->grantsFor(null);

        self::assertCount(1, $grants);
        self::assertTrue($grants[0]['revoked']);
        self::assertSame('https://agent.example/.well-known/ucp', $grants[0]['client_id']);
    }

    public function testRevokeStampsTheRefreshTokenRowAndReturnsTheCount(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeStatement')
            ->with(self::callback(
                static fn(string $sql): bool => (
                    str_contains($sql, 'UPDATE')
                    && str_contains($sql, 'swag_agentic_commerce_ucp_oauth_refresh_token')
                    && str_contains($sql, 'revoked_at')
                ),
            ))
            ->willReturn(2);

        $revoked = (new AcAgentGrantReader($connection))->revoke(
            '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
            'https://agent.example/.well-known/ucp',
        );

        self::assertSame(2, $revoked);
    }
}
