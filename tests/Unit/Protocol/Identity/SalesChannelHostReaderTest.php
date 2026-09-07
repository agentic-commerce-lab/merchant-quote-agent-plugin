<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Identity;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Identity\SalesChannelHostReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The authority this installation signs acts under has to equal the authority
 * the same installation PUBLISHES a did:web document for, or a counterparty
 * resolving an act's `sender_verification_method` fetches a document
 * describing a different DID and rejects it (spec acceptance criteria 1 and
 * 5). The publishing side is Symfony's `Request::getHttpHost()`, which
 * lower-cases the host and drops the scheme's default port, so a raw
 * `parse_url()` of the configured domain does not agree with it — these are
 * the shapes where it used to diverge.
 */
final class SalesChannelHostReaderTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function domains(): iterable
    {
        yield 'explicit https default port' => ['https://shop.example:443/', 'shop.example'];
        yield 'explicit http default port' => ['http://shop.example:80/', 'shop.example'];
        yield 'mixed case host' => ['https://Shop.Example/', 'shop.example'];
        yield 'fully qualified trailing dot' => ['https://shop.example./', 'shop.example'];
        yield 'genuine non-default port survives' => ['https://shop.example:8095/', 'shop.example:8095'];
        yield 'http on a non-default port survives' => ['http://shop.example:8095/', 'shop.example:8095'];
        yield 'plain host' => ['https://shop.example/', 'shop.example'];
    }

    #[DataProvider('domains')]
    public function testItNormalisesTheAuthorityTheWayTheRequestSideDoes(string $url, string $expected): void
    {
        self::assertSame($expected, $this->reader($url)->hostFor(Uuid::randomHex()));
    }

    /**
     * The https default port on an http URL is NOT the http default, so it is
     * a real port and has to survive — dropping 443 unconditionally would
     * publish a DID the shop does not answer on.
     */
    public function testItKeepsAPortThatIsNotItsOwnSchemesDefault(): void
    {
        self::assertSame('shop.example:443', $this->reader('http://shop.example:443/')->hostFor(Uuid::randomHex()));
    }

    public function testAChannelWithNoDomainHasNoAuthority(): void
    {
        self::assertNull($this->reader('')->hostFor(Uuid::randomHex()));
    }

    /**
     * `Uuid::fromHexToBytes()` throws on a non-hex id, so the guard has to
     * come before the query, not after it.
     */
    public function testANonUuidSalesChannelIdIsRejectedWithoutQuerying(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        self::assertNull((new SalesChannelHostReader($connection))->hostFor('not-a-uuid'));
    }

    private function reader(string $url): SalesChannelHostReader
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($url);

        return new SalesChannelHostReader($connection);
    }
}
