<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsFactory;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
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
    ): QuoteAgentSettingsReader {
        $values = [
            'enabled' => true,
            'llmApiKey' => 'sk-from-config',
            'llmBaseUrl' => 'https://api.openai.com/v1',
            'llmModel' => 'gpt-4o-mini',
            'maxDiscountPercent' => 10.0,
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

        return new QuoteAgentSettingsReader($config, $factory, $envApiKey);
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
}
