<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use MerchantQuoteAgentPlugin\Strategy\UnknownStrategy;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Validator\Validation;

/**
 * @mago-expect lint:no-literal-password
 *
 * `sk-from-config` and `sk-from-env` are fixture credentials for a reader that
 * never talks to a real API, not real secrets.
 */
final class QuoteAgentSettingsReaderTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function reader(
        array $overrides = [],
        #[\SensitiveParameter]
        ?string $envApiKey = null,
        ?StrategyResolver $strategies = null,
    ): QuoteAgentSettingsReader {
        $values = [
            'enabled' => true,
            'llmApiKey' => 'sk-from-config',
            'llmBaseUrl' => 'https://api.openai.com/v1',
            'llmModel' => 'gpt-4o-mini',
            'maxDiscountPercent' => 10.0,
            'validityDays' => 14,
            ...$overrides,
        ];

        $config = $this->createMock(SystemConfigService::class);
        $config
            ->method('get')
            ->willReturnCallback(
                static fn(string $key): mixed => (
                    $values[str_replace(QuoteAgentSettingsReader::DOMAIN, '', $key)] ?? null
                ),
            );

        $factory = new QuoteAgentSettingsFactory(
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        return new QuoteAgentSettingsReader(
            $config,
            $factory,
            $strategies ?? $this->createMock(StrategyResolver::class),
            $envApiKey,
        );
    }

    private function resolverReturning(ResolvedStrategy $resolved): StrategyResolver
    {
        $resolver = $this->createMock(StrategyResolver::class);
        $resolver->method('resolve')->willReturn($resolved);

        return $resolver;
    }

    public function testTheConfiguredKeyIsUsedWhenNoEnvironmentKeyIsSet(): void
    {
        $settings = $this->reader()->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertSame('sk-from-config', $settings->llm->apiKey);
    }

    public function testTheEnvironmentKeyWinsOverTheConfiguredOne(): void
    {
        $settings = $this->reader(envApiKey: 'sk-from-env')->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertSame('sk-from-env', $settings->llm->apiKey);
    }

    public function testABlankEnvironmentKeyDoesNotOverrideTheConfiguredOne(): void
    {
        $settings = $this->reader(envApiKey: '   ')->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertSame('sk-from-config', $settings->llm->apiKey);
    }

    public function testTheEnvironmentKeySatisfiesAnEmptyConfigField(): void
    {
        $settings = $this->reader(['llmApiKey' => ''], envApiKey: 'sk-from-env')->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertSame('sk-from-env', $settings->llm->apiKey);
    }

    public function testNeitherRouteSetIsRefusedAndNamesBothRoutes(): void
    {
        try {
            $this->reader(['llmApiKey' => ''])->forSalesChannel(null);
            self::fail('Expected InvalidQuoteAgentConfiguration.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString('MQA_LLM_API_KEY', implode(' ', $e->problems));
        }
    }

    public function testNoStrategyIdMeansNoStrategyPrompt(): void
    {
        $settings = $this->reader()->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertNull($settings->strategyPrompt);
        self::assertNull($settings->strategyVersionId);
    }

    public function testTheSelectedStrategyBecomesThePromptAndTheVersionId(): void
    {
        $settings = $this->reader([
            'negotiationStrategyId' => '0123456789abcdef0123456789abcdef',
        ], strategies: $this->resolverReturning(new ResolvedStrategy('feedfacefeedfacefeedfacefeedface', 'hold firm')))->forSalesChannel(
            null,
        );

        self::assertNotNull($settings);
        self::assertSame('hold firm', $settings->strategyPrompt);
        self::assertSame('feedfacefeedfacefeedfacefeedface', $settings->strategyVersionId);
    }

    public function testAnUnusableStrategyIsRefusedAsAConfigurationProblem(): void
    {
        $resolver = $this->createMock(StrategyResolver::class);
        $resolver->method('resolve')->willThrowException(UnknownStrategy::archived('0123456789abcdef0123456789abcdef'));

        try {
            $this->reader([
                'negotiationStrategyId' => '0123456789abcdef0123456789abcdef',
            ], strategies: $resolver)->forSalesChannel(null);
            self::fail('Expected InvalidQuoteAgentConfiguration.');
        } catch (InvalidQuoteAgentConfiguration $e) {
            self::assertStringContainsString('archived', implode(' ', $e->problems));
        }
    }
}
