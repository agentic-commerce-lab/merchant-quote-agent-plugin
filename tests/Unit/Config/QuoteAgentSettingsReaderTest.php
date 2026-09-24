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
 *
 * @mago-expect lint:too-many-methods
 * Sixteen cases plus two private helpers (the reader builder and a strategy
 * resolver stub) shared across them, including the #140 regression coverage
 * for notifyBuyerOnEscalation(), the mirrored default-off pins for
 * assistantQuoteRequests() and draftMode(), and Draft Mode's silencing of the
 * escalation notice.
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

    public function testBuyerNotificationDefaultsToTellingTheBuyer(): void
    {
        self::assertTrue($this->reader()->notifyBuyerOnEscalation(null));
    }

    public function testBuyerNotificationIsSilentOnlyWhenExplicitlyFalse(): void
    {
        self::assertFalse($this->reader(['notifyBuyerOnEscalation' => false])->notifyBuyerOnEscalation(null));
        self::assertTrue($this->reader(['notifyBuyerOnEscalation' => true])->notifyBuyerOnEscalation(null));
    }

    /**
     * The regression this whole change exists for (#140). A NotConfigured
     * escalation is raised FROM forSalesChannel() throwing, and then asks
     * whether to tell the buyer. That second question must still have an
     * answer.
     */
    public function testBuyerNotificationAnswersWhileTheConfigurationIsUnusable(): void
    {
        $reader = $this->reader(['llmApiKey' => '', 'llmModel' => null]);

        try {
            $reader->forSalesChannel(null);
            self::fail('The fixture configuration should be unusable.');
        } catch (InvalidQuoteAgentConfiguration) {
            // expected
        }

        self::assertTrue($reader->notifyBuyerOnEscalation(null));
    }

    /**
     * The opposite default from notifyBuyerOnEscalation, and just as load
     * bearing: the assistant acts for the buyer, so an unset key must mean
     * off, not on. `RequestQuoteToolFactoryTest` mocks this method's return
     * value directly, so only a test against the reader's own `=== true`
     * check catches a regression to `!== false`.
     */
    public function testAssistantQuoteRequestsDefaultsToOff(): void
    {
        self::assertFalse($this->reader()->assistantQuoteRequests(null));
    }

    public function testAssistantQuoteRequestsIsOnOnlyWhenExplicitlyTrue(): void
    {
        self::assertFalse($this->reader(['assistantQuoteRequests' => false])->assistantQuoteRequests(null));
        self::assertTrue($this->reader(['assistantQuoteRequests' => true])->assistantQuoteRequests(null));
    }

    public function testDraftModeIsOnOnlyWhenExplicitlyTrue(): void
    {
        self::assertFalse($this->reader()->draftMode(null));
        self::assertFalse($this->reader(['draftMode' => false])->draftMode(null));
        self::assertTrue($this->reader(['draftMode' => true])->draftMode(null));
    }

    /** The agent never speaks to the buyer in Draft Mode — not even to say a human has it. */
    public function testDraftModeSilencesTheEscalationNotice(): void
    {
        self::assertFalse($this->reader([
            'notifyBuyerOnEscalation' => true,
            'draftMode' => true,
        ])->notifyBuyerOnEscalation(null));
    }

    public function testTheSettingsCarryDraftMode(): void
    {
        self::assertTrue($this->reader(['draftMode' => true])->forSalesChannel(null)?->draftMode);
        self::assertFalse($this->reader()->forSalesChannel(null)?->draftMode);
    }
}
