<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Identity;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnOrganizationNameReader;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The four-step fallback, in order. This name is published in a signed seller
 * mandate under the merchant's own DID, so every step of it is customer-facing.
 */
final class A2cnOrganizationNameReaderTest extends TestCase
{
    public function testTheMerchantsOwnValueWins(): void
    {
        $reader = $this->reader([
            A2cnIdentityResolver::ORGANIZATION_CONFIG_KEY => 'Ponyhof Handels GmbH',
            A2cnOrganizationNameReader::SHOP_NAME_CONFIG_KEY => 'Demostore',
        ], channelName: 'Storefront');

        self::assertSame('Ponyhof Handels GmbH', $reader->nameFor(Uuid::randomHex()));
    }

    /**
     * The step this test exists for. The sales channel name is an admin label —
     * "Storefront" on a stock installation — while the shop already has a
     * customer-facing name in Settings → Shop → Basic information. Publishing
     * the former in a signed mandate names the shop wrong.
     */
    public function testTheShopNameIsPreferredOverTheSalesChannelName(): void
    {
        $reader = $this->reader([
            A2cnOrganizationNameReader::SHOP_NAME_CONFIG_KEY => 'Demostore',
        ], channelName: 'Storefront');

        self::assertSame('Demostore', $reader->nameFor(Uuid::randomHex()));
    }

    /** Whitespace is not a configured name, on either key. */
    public function testABlankValueFallsThroughToTheNextStep(): void
    {
        $reader = $this->reader([
            A2cnIdentityResolver::ORGANIZATION_CONFIG_KEY => '   ',
            A2cnOrganizationNameReader::SHOP_NAME_CONFIG_KEY => "  Demostore\t",
        ], channelName: 'Storefront');

        self::assertSame('Demostore', $reader->nameFor(Uuid::randomHex()));
    }

    public function testTheSalesChannelNameIsTheLastNamedFallback(): void
    {
        self::assertSame('Storefront', $this->reader([], channelName: 'Storefront')->nameFor(Uuid::randomHex()));
    }

    public function testAShopThatNamesItselfNowhereIsStillNotAnonymous(): void
    {
        self::assertSame('Merchant', $this->reader([], channelName: '')->nameFor(Uuid::randomHex()));
    }

    /**
     * `Uuid::fromHexToBytes()` throws on a non-hex id, so the guard has to come
     * before the query — but the two config steps come before the guard, and a
     * shop name is just as valid an answer without a channel to scope it to.
     */
    public function testANonUuidSalesChannelIdStillGetsTheShopName(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $reader = new A2cnOrganizationNameReader($this->systemConfig([
            A2cnOrganizationNameReader::SHOP_NAME_CONFIG_KEY => 'Demostore',
        ]), $connection);

        self::assertSame('Demostore', $reader->nameFor('not-a-uuid'));
        self::assertSame('Demostore', $reader->nameFor(null));
    }

    /** @param array<string, string> $config */
    private function reader(array $config, string $channelName): A2cnOrganizationNameReader
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($channelName);

        return new A2cnOrganizationNameReader($this->systemConfig($config), $connection);
    }

    /** @param array<string, string> $config */
    private function systemConfig(array $config): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')->willReturnCallback(static fn(string $key): string => $config[$key] ?? '');

        return $systemConfig;
    }
}
