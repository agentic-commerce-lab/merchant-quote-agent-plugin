<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use PHPUnit\Framework\TestCase;

final class QuoteAgentSettingsTest extends TestCase
{
    public function testSettingsCarryThePolicyAndTheModelAccess(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0));
        $llm = new ModelAccess('sk-test', 'https://api.openai.com/v1', 'gpt-4o-mini');

        $settings = new QuoteAgentSettings($policy, llm: $llm, strategyPrompt: 'concede slowly');

        self::assertSame($policy, $settings->policy);
        self::assertSame($llm, $settings->llm);
        self::assertSame('concede slowly', $settings->strategyPrompt);
    }

    public function testTheExceptionKeepsEveryProblemAndListsThemInItsMessage(): void
    {
        $exception = new InvalidQuoteAgentConfiguration(['first problem', 'second problem']);

        self::assertSame(['first problem', 'second problem'], $exception->problems);
        self::assertStringContainsString('first problem', $exception->getMessage());
        self::assertStringContainsString('second problem', $exception->getMessage());
    }
}
