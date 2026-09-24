<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * @mago-expect lint:too-many-methods
 * Ten cases plus the shared `build()` fixture builder and the
 * `invalidConfigurations()` data provider, covering every branch of a
 * factory that maps a whole flat config array onto validated settings.
 */
final class QuoteAgentSettingsFactoryTest extends TestCase
{
    /**
     * @mago-expect lint:no-literal-password
     *
     * `sk-test` is a fixture credential for a factory that never talks to a
     * real API, not a real secret.
     *
     * @param array<string, mixed> $overrides
     */
    private static function build(array $overrides = []): mixed
    {
        $raw = [
            'enabled' => true,
            'llmApiKey' => 'sk-test',
            'llmBaseUrl' => 'https://api.openai.com/v1',
            'llmModel' => 'gpt-4o-mini',
            'negotiationStrategy' => 'open at 2%',
            'maxDiscountPercent' => 12.0,
            'counterOfferMaxPercent' => 18.0,
            // The admin's number field produces a bare float; an ISO-keyed
            // map is still accepted from `system:config:set --json`.
            'maxQuoteValueNet' => ['EUR' => 50_000.0, 'USD' => 55_000.0],
            'validityDays' => 14,
        ];

        $factory = new QuoteAgentSettingsFactory(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        return $factory->fromValues([...$raw, ...$overrides]);
    }

    public function testAFullConfigurationMapsOntoTheWholePolicyGraph(): void
    {
        $settings = self::build();

        self::assertNotNull($settings);
        self::assertSame(12.0, $settings->policy->price->maxDiscountPercent);
        self::assertSame(18.0, $settings->policy->price->counterOfferMaxPercent);
        self::assertSame(50_000.0, $settings->policy->price->valueCeiling?->netFor('EUR'));
        self::assertSame(55_000.0, $settings->policy->price->valueCeiling?->netFor('USD'));
        self::assertNull(
            $settings->policy->price->valueCeiling?->netFor('GBP'),
            'A currency the merchant left blank has an unknown ceiling, not an absent one.',
        );
        self::assertSame(14, $settings->policy->price->validityDays);
        self::assertSame('sk-test', $settings->llm->apiKey);
        self::assertSame('open at 2%', $settings->strategyPrompt);
    }

    public function testADisabledChannelReturnsNullAndIsNeverValidated(): void
    {
        // Every other field is garbage. A paused agent does not complain about
        // its own configuration.
        self::assertNull(self::build([
            'enabled' => false,
            'maxDiscountPercent' => 500.0,
            'llmApiKey' => '',
        ]));
    }

    public function testAnUntouchedInstallEscalatesEverythingRatherThanFailing(): void
    {
        // Every field a merchant may leave blank, left blank. `validityDays`
        // stopped being one of them in #57: config.xml ships 14, and a blank
        // one is refused by invalidConfigurations() above rather than read as
        // "valid for no days at all".
        $settings = self::build([
            'maxDiscountPercent' => null,
            'counterOfferMaxPercent' => null,
            'maxQuoteValueNet' => null,
        ]);

        self::assertNotNull($settings);
        self::assertSame(0.0, $settings->policy->price->maxDiscountPercent);
        self::assertNull($settings->policy->price->valueCeiling);
        self::assertSame(14, $settings->policy->price->validityDays);
    }

    /**
     * @mago-expect lint:no-literal-password
     *
     * The blank/whitespace `llmApiKey` values below are fixture non-secrets,
     * not real credentials.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'discount cap over 100' => [['maxDiscountPercent' => 150.0], 'price.maxDiscountPercent'];
        yield 'ceiling wrong type' => [['maxQuoteValueNet' => '50000'], 'maxQuoteValueNet'];
        yield 'ceiling map with a wrong-typed entry' => [['maxQuoteValueNet' => ['EUR' => 'lots']], 'EUR'];
        // The silent fallback #5 removes: a blank key is never a quiet switch
        // to deterministic decisions, even in rules-only mode.
        yield 'blank API key' => [['llmApiKey' => '   '], 'API key'];
        yield 'blank model name' => [['llmModel' => ''], 'model'];
        // #57. `null` is the merchant clearing the field and `0` is the
        // default this plugin used to ship; both wrote `+0 days` in
        // OfferApplier, i.e. an offer stamped as expired the moment it was
        // sent, and PositiveOrZero waved both through.
        yield 'cleared offer validity' => [['validityDays' => null], 'price.validityDays'];
        yield 'zero offer validity' => [['validityDays' => 0], 'price.validityDays'];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefusedWholeAndNamesTheField(array $overrides, string $expected): void
    {
        try {
            self::build($overrides);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        // Cross-mechanism: the validator and the credential check collect
        // independently, and neither short-circuits the other.
        try {
            self::build(['maxDiscountPercent' => 150.0, 'llmModel' => '']);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertCount(2, $e->problems);
        }
    }

    public function testTheModelNameReachesModelAccess(): void
    {
        $settings = self::build();

        self::assertNotNull($settings);
        self::assertSame('gpt-4o-mini', $settings->llm->model);
    }

    public function testFactoryParsesNotifyBuyerOnEscalation(): void
    {
        // Unset means on: a shop that never opened the config page still tells
        // the buyer a human has the quote.
        $settingsDefault = self::build();
        self::assertNotNull($settingsDefault);
        self::assertTrue($settingsDefault->notifyBuyerOnEscalation);

        $settingsOff = self::build(['notifyBuyerOnEscalation' => false]);
        self::assertNotNull($settingsOff);
        self::assertFalse($settingsOff->notifyBuyerOnEscalation);
    }

    public function testFactoryNamesTheConfigRungWhenAVersionIdIsSet(): void
    {
        $settings = self::build(['negotiationStrategyVersionId' => '0000000000000000000000000000bbbb']);

        self::assertNotNull($settings);
        self::assertSame(StrategyAssignmentSource::Config, $settings->strategyAssignmentSource);
    }

    public function testFactoryLeavesTheAssignmentSourceNullWithoutAVersionId(): void
    {
        // Absent entirely: an untouched install never configured a strategy,
        // so no rung gets credit for choosing one.
        $settingsAbsent = self::build();
        self::assertNotNull($settingsAbsent);
        self::assertNull($settingsAbsent->strategyAssignmentSource);

        // Present but blank: RawConfigValue::string() treats a whitespace-only
        // value the same as absent, and this must not read as `config` either
        // -- otherwise a shop with the agent on but nothing configured would
        // claim the bottom rung chose a strategy it never sent.
        $settingsBlank = self::build(['negotiationStrategyVersionId' => '   ']);
        self::assertNotNull($settingsBlank);
        self::assertNull($settingsBlank->strategyAssignmentSource);
    }

    public function testDraftModeSurvivesWithPolicyAndWithStrategy(): void
    {
        $settings = self::build(['draftMode' => true]);
        self::assertNotNull($settings);

        self::assertTrue($settings->withPolicy($settings->policy)->draftMode);
        self::assertTrue($settings->withStrategy(
            new ResolvedStrategy(versionId: '0000000000000000000000000000cccc', prompt: 'p'),
            StrategyAssignmentSource::Config,
        )->draftMode);
    }

    public function testTheMinimumMarginMapsOntoThePriceLimits(): void
    {
        self::assertSame(10.0, self::build(['minMarginPercent' => 10.0])?->policy->price->minMarginPercent);
    }

    public function testABlankMinimumMarginMeansNoFloor(): void
    {
        $absent = self::build();
        self::assertNotNull($absent);
        self::assertNull($absent->policy->price->minMarginPercent);

        $cleared = self::build(['minMarginPercent' => null]);
        self::assertNotNull($cleared);
        self::assertNull($cleared->policy->price->minMarginPercent);
    }
}
