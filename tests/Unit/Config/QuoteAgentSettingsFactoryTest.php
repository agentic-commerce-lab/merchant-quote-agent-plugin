<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

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
        $settings = self::build([
            'maxDiscountPercent' => null,
            'counterOfferMaxPercent' => null,
            'maxQuoteValueNet' => null,
            'validityDays' => null,
        ]);

        self::assertNotNull($settings);
        self::assertSame(0.0, $settings->policy->price->maxDiscountPercent);
        self::assertNull($settings->policy->price->valueCeiling);
        self::assertSame(0, $settings->policy->price->validityDays);
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
}
