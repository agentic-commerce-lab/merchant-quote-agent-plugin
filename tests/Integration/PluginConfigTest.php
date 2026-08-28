<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class PluginConfigTest extends IntegrationTestCase
{
    /**
     * @mago-expect lint:no-literal-password
     *
     * `sk-probe` is a fixture credential round-tripped through system_config
     * in a rolled-back transaction, not a real secret.
     */
    public function testEveryConfiguredKeyIsReachableThroughSystemConfig(): void
    {
        $config = self::systemConfig();

        // Writing then reading proves the key round-trips under the domain the
        // reader uses. A typo in config.xml or in the domain shows up here and
        // nowhere else — a broken config.xml is silent, not an error.
        foreach (['enabled' => true, 'maxDiscountPercent' => 12.0, 'llmApiKey' => 'sk-probe'] as $key => $value) {
            $config->set(QuoteAgentSettingsReader::DOMAIN . $key, $value);
            self::assertSame($value, $config->get(QuoteAgentSettingsReader::DOMAIN . $key));
        }
    }

    public function testTheDefaultBaseUrlIsShippedByConfigXmlRatherThanOnlyByTheFactory(): void
    {
        self::assertSame(
            'https://api.openai.com/v1',
            self::systemConfig()->get(QuoteAgentSettingsReader::DOMAIN . 'llmBaseUrl'),
        );
    }

    public function testASalesChannelValueOverridesTheGlobalOne(): void
    {
        $config = self::systemConfig();
        $salesChannelId = self::anySalesChannelId();
        $key = QuoteAgentSettingsReader::DOMAIN . 'maxDiscountPercent';

        $config->set($key, 5.0);
        $config->set($key, 25.0, $salesChannelId);

        self::assertSame(5.0, $config->get($key));
        self::assertSame(25.0, $config->get($key, $salesChannelId));
    }

    public function testTheReaderResolvesFromTheContainerAndHonoursTheKillSwitch(): void
    {
        $reader = static::getContainer()->get(QuoteAgentSettingsReader::class);
        self::assertInstanceOf(QuoteAgentSettingsReader::class, $reader);

        self::systemConfig()->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', false);

        self::assertNull($reader->forSalesChannel(null), 'A disabled channel must read as no settings at all.');
    }

    public function testAnEnabledChannelWithoutAKeyThrowsRatherThanFallingBackQuietly(): void
    {
        $reader = static::getContainer()->get(QuoteAgentSettingsReader::class);
        self::assertInstanceOf(QuoteAgentSettingsReader::class, $reader);

        $config = self::systemConfig();
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'rulesOnlyMode', false);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');

        $this->expectException(\MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration::class);

        $reader->forSalesChannel(null);
    }

    private static function systemConfig(): SystemConfigService
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return $config;
    }

    private static function anySalesChannelId(): string
    {
        $repository = static::getContainer()->get('sales_channel.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $id = $repository->searchIds(new Criteria(), Context::createDefaultContext())->firstId();
        self::assertIsString($id, 'The shop has no sales channel.');

        return $id;
    }
}
