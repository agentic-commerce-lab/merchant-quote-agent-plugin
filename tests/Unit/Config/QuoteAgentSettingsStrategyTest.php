<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Strategy\ResolvedStrategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentSource;
use PHPUnit\Framework\TestCase;

final class QuoteAgentSettingsStrategyTest extends TestCase
{
    public function testWithStrategyReplacesThePromptTheVersionAndTheSource(): void
    {
        $settings = self::settings();

        $replaced = $settings->withStrategy(
            new ResolvedStrategy('1111111111111111111111111111aaaa', 'hold firm'),
            StrategyAssignmentSource::Split,
        );

        self::assertSame('hold firm', $replaced->strategyPrompt);
        self::assertSame('1111111111111111111111111111aaaa', $replaced->strategyVersionId);
        self::assertSame(StrategyAssignmentSource::Split, $replaced->strategyAssignmentSource);
    }

    public function testWithStrategyCarriesEveryOtherFieldAcross(): void
    {
        $settings = self::settings();

        $replaced = $settings->withStrategy(
            new ResolvedStrategy('1111111111111111111111111111aaaa', 'hold firm'),
            StrategyAssignmentSource::Pin,
        );

        self::assertSame($settings->policy, $replaced->policy);
        self::assertSame($settings->llm, $replaced->llm);
        self::assertSame($settings->notifyBuyerOnEscalation, $replaced->notifyBuyerOnEscalation);
    }

    /**
     * withPolicy() predates the assignment source and must not silently drop
     * it -- a clone that loses the source would make every decision row it
     * touches read as `config`.
     */
    public function testWithPolicyKeepsTheAssignmentSource(): void
    {
        $settings = self::settings()
            ->withStrategy(
                new ResolvedStrategy('1111111111111111111111111111aaaa', 'hold firm'),
                StrategyAssignmentSource::Rule,
            );

        $replaced = $settings->withPolicy($settings->policy);

        self::assertSame(StrategyAssignmentSource::Rule, $replaced->strategyAssignmentSource);
    }

    private static function settings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            new ModelAccess('sk-test', 'https://api.openai.com/v1', 'gpt-4o-mini'),
            'be nice',
            true,
            '0000000000000000000000000000bbbb',
            StrategyAssignmentSource::Config,
        );
    }
}
