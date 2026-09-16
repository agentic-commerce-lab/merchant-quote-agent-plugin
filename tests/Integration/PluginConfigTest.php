<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\CachedSystemConfigLoader;
use Shopware\Core\System\SystemConfig\Store\MemoizedSystemConfigStore;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * @mago-expect lint:too-many-methods
 *
 * Test class with many methods, necessary for comprehensive config coverage.
 */
final class PluginConfigTest extends IntegrationTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTransactionBehaviour rolls the database back between
        // tests, but config reads go through two caches neither of which
        // knows that happened: MemoizedSystemConfigStore (an in-process
        // array) and, behind it, CachedSystemConfigLoader (a persisted pool).
        // A set() in one test primes both with post-write values that
        // survive the rollback and leak into a later test that only reads.
        // Clearing both is what makes every test see what is actually
        // persisted, regardless of what ran before it.
        $store = static::getContainer()->get(MemoizedSystemConfigStore::class);
        self::assertInstanceOf(MemoizedSystemConfigStore::class, $store);
        $store->reset();

        $cacheInvalidator = static::getContainer()->get(CacheInvalidator::class);
        self::assertInstanceOf(CacheInvalidator::class, $cacheInvalidator);
        $cacheInvalidator->invalidate([CachedSystemConfigLoader::CACHE_TAG], true);
    }

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
        foreach ([
            'enabled' => true,
            'maxDiscountPercent' => 12.0,
            'llmApiKey' => 'sk-probe',
            'llmModel' => 'gpt-4o-mini',
        ] as $key => $value) {
            $config->set(QuoteAgentSettingsReader::DOMAIN . $key, $value);
            self::assertSame($value, $config->get(QuoteAgentSettingsReader::DOMAIN . $key));
        }
    }

    /**
     * The factory throws on a present-but-wrong-typed value, so the type
     * Shopware persists each config.xml default with is load-bearing: a
     * string "0" for maxDiscountPercent would hard-refuse a freshly
     * installed, just-enabled shop. These reads deliberately happen with no
     * set() beforehand — they are the install-time values.
     */
    public function testInstallTimeDefaultsArePersistedWithNativeTypes(): void
    {
        $config = self::systemConfig();

        self::assertSame(false, $config->get(QuoteAgentSettingsReader::DOMAIN . 'enabled'));
        self::assertSame(0.0, $config->get(QuoteAgentSettingsReader::DOMAIN . 'maxDiscountPercent'));
        self::assertSame(14, $config->get(QuoteAgentSettingsReader::DOMAIN . 'validityDays'));
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
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');

        $this->expectException(\MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration::class);

        $reader->forSalesChannel(null);
    }

    /**
     * The SLA benchmarks the dashboard's escalation resolution time and
     * steers nothing in the pipeline, which is why it is deliberately absent
     * from QuoteAgentSettings — see testTheSlaDoesNotReachTheGuardrailEngine.
     */
    public function testTheEscalationSlaIsConfigurable(): void
    {
        $config = self::systemConfig();
        $key = QuoteAgentSettingsReader::DOMAIN . 'escalationSlaHours';

        $config->set($key, 24.0);

        self::assertSame(24.0, $config->get($key));
    }

    /**
     * The SLA must not become a guardrail. It is a reporting benchmark, so a
     * shop that sets it must not thereby change what the agent does.
     */
    public function testTheSlaDoesNotReachTheGuardrailEngine(): void
    {
        self::assertStringNotContainsString(
            'escalationSlaHours',
            (string) file_get_contents(__DIR__ . '/../../src/Config/QuoteAgentSettingsReader.php'),
        );
        self::assertStringNotContainsString(
            'escalationSlaHours',
            (string) file_get_contents(__DIR__ . '/../../src/Config/QuoteAgentSettings.php'),
        );
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
