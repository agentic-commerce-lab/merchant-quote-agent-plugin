<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SystemConfig\CachedSystemConfigLoader;
use Shopware\Core\System\SystemConfig\Service\ConfigurationService;
use Shopware\Core\System\SystemConfig\Store\MemoizedSystemConfigStore;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\SystemConfig\Validation\SystemConfigValidator;
use Symfony\Component\Validator\ConstraintViolationInterface;

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
        self::clearConfigCaches();
    }

    /**
     * Once more on the way out: testTheRoundingModeDefaultIsPersistedAsItsEnumString
     * saves every config.xml default, and the caches refill from those
     * uncommitted values. The rollback restores the rows but not the caches,
     * so without this every later test class in the run would read the
     * install defaults (agent disabled, 0% maximum) instead of the shop's
     * real configuration.
     */
    #[\Override]
    protected function tearDown(): void
    {
        self::clearConfigCaches();
        parent::tearDown();
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

    /**
     * @mago-expect lint:no-literal-password
     *
     * QA-07 (2026-10-01): a channel override of 0 must read as 0 rather than
     * fall back to the global cap because 0 is falsy. It does; the report
     * reproduced only through the admin number field, which commits a typed
     * value on blur and so can save nothing. This pins the read path end to
     * end, through the reader and the factory's validation, so a "fix" there
     * cannot quietly regress it.
     *
     * Every key the reader validates is named in both scopes rather than left
     * to the shop's stored values: a configured test shop otherwise decides
     * what this reads (an override on the channel wins over anything set
     * globally). Null on the channel deletes its override. The transaction
     * rollback and clearConfigCaches() restore both scopes afterwards.
     * `sk-probe` is a fixture credential, as above.
     */
    public function testAChannelOverrideOfZeroBeatsTheGlobalCap(): void
    {
        $config = self::systemConfig();
        $salesChannelId = self::anySalesChannelId();

        foreach ([
            'enabled' => true,
            'llmApiKey' => 'sk-probe',
            'llmBaseUrl' => 'https://api.openai.com/v1',
            'llmModel' => 'gpt-4o-mini',
            'negotiationStrategyId' => null,
            'counterOfferMaxPercent' => null,
            'minMarginPercent' => null,
            'roundingMode' => 'off',
            'roundingStep' => null,
            'maxQuoteValueNet' => null,
            'validityDays' => 14,
            'draftMode' => false,
        ] as $key => $value) {
            $config->set(QuoteAgentSettingsReader::DOMAIN . $key, $value);
            $config->set(QuoteAgentSettingsReader::DOMAIN . $key, null, $salesChannelId);
        }

        $config->set(QuoteAgentSettingsReader::DOMAIN . 'maxDiscountPercent', 15.0);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'maxDiscountPercent', 0.0, $salesChannelId);

        $reader = static::getContainer()->get(QuoteAgentSettingsReader::class);
        self::assertInstanceOf(QuoteAgentSettingsReader::class, $reader);

        self::assertSame(0.0, $reader->forSalesChannel($salesChannelId)?->policy->price->maxDiscountPercent);
        self::assertSame(15.0, $reader->forSalesChannel(null)?->policy->price->maxDiscountPercent);
    }

    /**
     * QA-07 hardening: the admin saves through the batch endpoint, which runs
     * core's SystemConfigValidator over config.xml's <min>/<max>. A percentage
     * outside its range is refused at save time instead of being stored and
     * then failing QuoteLimits on every pass, which takes the channel out of
     * service. Blank still saves: a cleared counter field means no band.
     *
     * Built rather than fetched: core inlines SystemConfigValidator into its
     * controller, so the test container has no entry for it.
     */
    public function testTheAdminSaveRefusesPercentagesOutsideTheirRange(): void
    {
        $container = static::getContainer();
        $configuration = $container->get(ConfigurationService::class);
        self::assertInstanceOf(ConfigurationService::class, $configuration);
        $dataValidator = $container->get(DataValidator::class);
        self::assertInstanceOf(DataValidator::class, $dataValidator);

        $validator = new SystemConfigValidator($configuration, $dataValidator);
        $context = Context::createDefaultContext();
        $domain = QuoteAgentSettingsReader::DOMAIN;

        // The edges and a blank are accepted; this throws if they are not.
        $validator->validate(
            [
                'null' => [
                    $domain . 'maxDiscountPercent' => 0.0,
                    $domain . 'counterOfferMaxPercent' => 100.0,
                    // A markup may exceed 100% (QuoteLimits::$minMarginPercent).
                    $domain . 'minMarginPercent' => 250.0,
                ],
                self::anySalesChannelId() => [$domain . 'counterOfferMaxPercent' => null],
            ],
            $context,
        );

        $refused = [];

        try {
            $validator->validate([
                'null' => [
                    $domain . 'maxDiscountPercent' => -1.0,
                    $domain . 'counterOfferMaxPercent' => 101.0,
                    $domain . 'minMarginPercent' => -0.5,
                ],
            ], $context);
        } catch (ConstraintViolationException $e) {
            $refused = array_map(
                static fn(ConstraintViolationInterface $violation): string => $violation->getPropertyPath(),
                iterator_to_array($e->getViolations()),
            );
        }

        sort($refused);

        self::assertSame(
            [
                '/null/' . $domain . 'counterOfferMaxPercent',
                '/null/' . $domain . 'maxDiscountPercent',
                '/null/' . $domain . 'minMarginPercent',
            ],
            $refused,
        );
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

    /**
     * roundingMode ships `off` as a string, the type RoundingMode::from()
     * reads. It is saved here the way install and update save config.xml's
     * defaults (override on, inside this rolled-back transaction), because the
     * test-shop sync never re-saves them. A shop installed before the field
     * existed has no row for it at all, and that reads as off too, just not
     * through this path.
     */
    public function testTheRoundingModeDefaultIsPersistedAsItsEnumString(): void
    {
        $config = self::systemConfig();
        $config->savePluginConfiguration(static::getKernel()->getBundle('MerchantQuoteAgentPlugin'), true);

        self::assertSame('off', $config->get(QuoteAgentSettingsReader::DOMAIN . 'roundingMode'));
    }

    /**
     * DatabaseTransactionBehaviour rolls the database back between tests,
     * but config reads go through two caches neither of which knows that
     * happened: MemoizedSystemConfigStore (an in-process array) and, behind
     * it, CachedSystemConfigLoader (a persisted pool). A write in one test
     * primes both with post-write values that survive the rollback and leak
     * into a later test that only reads. Clearing both is what makes every
     * test see what is actually persisted, regardless of what ran before it.
     */
    private static function clearConfigCaches(): void
    {
        $store = static::getContainer()->get(MemoizedSystemConfigStore::class);
        self::assertInstanceOf(MemoizedSystemConfigStore::class, $store);
        $store->reset();

        $cacheInvalidator = static::getContainer()->get(CacheInvalidator::class);
        self::assertInstanceOf(CacheInvalidator::class, $cacheInvalidator);
        $cacheInvalidator->invalidate([CachedSystemConfigLoader::CACHE_TAG], true);
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
