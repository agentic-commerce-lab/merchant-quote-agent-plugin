<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Improvement\ImprovementCadence;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettingsReader;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The contract every later task relies on: an instance always means
 * "enabled, and the agent underneath it is usable" — mirroring
 * QuoteAgentSettingsFactory's "a QuoteAgentSettings instance always means
 * enabled and valid".
 *
 * @mago-expect lint:no-literal-password
 *
 * `sk-agent` and `agent-key` are fixture credentials for a reader that never
 * talks to a real API, not real secrets.
 */
final class ImprovementSettingsReaderTest extends TestCase
{
    public function testNullWhenTheImprovementFeatureItselfIsOff(): void
    {
        $reader = $this->reader(improvementValues: ['improvementEnabled' => false], agent: $this->agentSettings());

        self::assertNull($reader->forSalesChannel(null));
    }

    public function testNullWhenTheAgentIsOffEvenIfImprovementIsOn(): void
    {
        $reader = $this->reader(improvementValues: ['improvementEnabled' => true], agent: null);

        self::assertNull($reader->forSalesChannel(null));
    }

    public function testEnabledReadsTheConfiguredCadenceSampleAndCandidates(): void
    {
        $reader = $this->reader(improvementValues: [
            'improvementEnabled' => true,
            'improvementCadence' => 'weekly',
            'improvementSampleSize' => 42,
            'improvementCandidates' => 3,
        ], agent: $this->agentSettings());

        $settings = $reader->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertTrue($settings->enabled);
        self::assertSame(ImprovementCadence::Weekly, $settings->cadence);
        self::assertSame(42, $settings->sampleSize);
        self::assertSame(3, $settings->candidates);
    }

    public function testModelFallsBackToTheAgentsModelFieldByFieldNotAsAWhole(): void
    {
        $reader = $this->reader(
            improvementValues: [
                'improvementEnabled' => true,
                'improvementLlmModel' => 'gpt-5-review',
            ],
            agent: $this->agentSettings(apiKey: 'agent-key', baseUrl: 'https://agent.example/v1', model: 'agent-model'),
        );

        $settings = $reader->forSalesChannel(null);

        self::assertNotNull($settings);
        // The merchant only set the model: provider stays the agent's.
        self::assertSame('agent-key', $settings->llm->apiKey);
        self::assertSame('https://agent.example/v1', $settings->llm->baseUrl);
        self::assertSame('gpt-5-review', $settings->llm->model);
    }

    public function testBlankModelFallsBackToTheAgentsModel(): void
    {
        $reader = $this->reader(improvementValues: [
            'improvementEnabled' => true,
            'improvementLlmModel' => '  ',
        ], agent: $this->agentSettings(model: 'agent-model'));

        $settings = $reader->forSalesChannel(null);

        self::assertNotNull($settings);
        self::assertSame('agent-model', $settings->llm->model);
    }

    /** @param array<string, mixed> $improvementValues */
    private function reader(array $improvementValues, ?QuoteAgentSettings $agent): ImprovementSettingsReader
    {
        $config = $this->createMock(SystemConfigService::class);
        $config
            ->method('get')
            ->willReturnCallback(
                static fn(string $key): mixed => (
                    $improvementValues[str_replace(ImprovementSettingsReader::DOMAIN, '', $key)] ?? null
                ),
            );

        $agentReader = $this->createMock(QuoteAgentSettingsSource::class);
        $agentReader->method('forSalesChannel')->willReturn($agent);

        return new ImprovementSettingsReader($config, $agentReader);
    }

    private function agentSettings(
        #[\SensitiveParameter]
        string $apiKey = 'sk-agent',
        string $baseUrl = 'https://api.openai.com/v1',
        string $model = 'gpt-4o-mini',
    ): QuoteAgentSettings {
        return new QuoteAgentSettings(
            new NegotiationPolicy(new QuoteLimits(maxDiscountPercent: 10.0, validityDays: 14)),
            new ModelAccess($apiKey, $baseUrl, $model),
            null,
        );
    }
}
