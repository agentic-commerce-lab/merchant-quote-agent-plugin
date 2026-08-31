<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\AcOAuthAccessTokenReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcOAuthAccessTokenReader::class)]
final class AcOAuthAccessTokenReaderTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public function testItLooksTheTokenUpByRawSha256AndSalesChannel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(
                    static fn(string $sql): bool => (
                        str_contains($sql, 'swag_agentic_commerce_ucp_oauth_access_token')
                        && str_contains($sql, 'swag_agentic_commerce_ucp_oauth_refresh_token')
                        && str_contains($sql, 'LEFT JOIN')
                    ),
                ),
                self::callback(
                    static fn(array $params): bool => $params['tokenHash'] === hash('sha256', 'ucp_at_abc', true),
                ),
            )
            ->willReturn([
                'sales_channel_id' => self::SALES_CHANNEL_ID,
                'client_id' => 'agent-client',
                'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
                'scope' => 'dev.ucp.shopping.cart:manage com.shopware.quote:manage',
                'expires_at' => time() + 60,
                'revoked_at' => null,
            ]);

        $info = (new AcOAuthAccessTokenReader($connection))->find('ucp_at_abc', self::SALES_CHANNEL_ID);

        self::assertNotNull($info);
        self::assertSame('0191d3d0a0b071bd9c1a0d9d1a3f9f02', $info->subject);
        self::assertSame('agent-client', $info->clientId);
        self::assertTrue($info->hasScope('com.shopware.quote:manage'));
    }

    public function testItReturnsNullForAnUnknownToken(): void
    {
        self::assertNull($this->readerReturning(false)->find('ucp_at_abc', self::SALES_CHANNEL_ID));
    }

    public function testItReturnsNullForAnExpiredToken(): void
    {
        $reader = $this->readerReturning($this->row(['expires_at' => time() - 1]));

        self::assertNull($reader->find('ucp_at_abc', self::SALES_CHANNEL_ID));
    }

    public function testItReturnsNullWhenTheLinkWasRevoked(): void
    {
        $reader = $this->readerReturning($this->row(['revoked_at' => time() - 5]));

        self::assertNull($reader->find('ucp_at_abc', self::SALES_CHANNEL_ID));
    }

    /**
     * @param array<string, mixed>|false $result
     */
    private function readerReturning(array|false $result): AcOAuthAccessTokenReader
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($result);

        return new AcOAuthAccessTokenReader($connection);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'sales_channel_id' => self::SALES_CHANNEL_ID,
            'client_id' => 'agent-client',
            'subject' => '0191d3d0a0b071bd9c1a0d9d1a3f9f02',
            'scope' => 'dev.ucp.shopping.cart:manage',
            'expires_at' => time() + 60,
            'revoked_at' => null,
        ], $overrides);
    }
}
