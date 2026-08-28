<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromptComposerTest extends TestCase
{
    private static function composer(): PromptComposer
    {
        return new PromptComposer('EXTRACT BASE', 'NEGOTIATE BASE', 'REPLY BASE {{tone}} END');
    }

    private static function settings(?string $strategy = null, ?string $tone = null): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0, replyTone: $tone)),
            rulesOnly: false,
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: $strategy,
        );
    }

    public function testTheExtractPromptIsVerbatim(): void
    {
        self::assertSame('EXTRACT BASE', self::composer()->extract()->text);
    }

    public function testAMerchantStrategyIsAppendedInADelimitedSection(): void
    {
        $composed = self::composer()->negotiate(self::settings(strategy: 'open at 2%'))->text;

        self::assertSame(
            "NEGOTIATE BASE\n\n## Merchant strategy\n\nopen at 2%",
            $composed,
            'The strategy must be delimited so the model cannot read it as part of the base instructions.',
        );
    }

    public function testNoStrategyLeavesTheBasePromptUntouched(): void
    {
        self::assertSame('NEGOTIATE BASE', self::composer()->negotiate(self::settings())->text);
    }

    #[DataProvider('replyToneTestCases')]
    public function testToneHandling(?string $tone, string $expected, bool $shouldContainTone): void
    {
        $composed = self::composer()->reply(self::settings(tone: $tone))->text;

        self::assertSame($expected, $composed);
        if ($shouldContainTone) {
            self::assertStringNotContainsString('{{tone}}', $composed);
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: bool}>
     */
    public static function replyToneTestCases(): array
    {
        return [
            'tone formal' => ['formal', 'REPLY BASE formal END', true],
            'tone blank gets neutral' => [null, 'REPLY BASE neutral and professional END', true],
        ];
    }

    public function testTheHashIsTheSha256OfTheComposedText(): void
    {
        $composed = self::composer()->negotiate(self::settings(strategy: 'open at 2%'));

        self::assertSame(hash('sha256', $composed->text), $composed->hash);
    }

    public function testHashesChangeWhenStrategyOrToneChange(): void
    {
        // This is what lets #22 say which prompt produced which outcome.
        $strategyHashA = self::composer()->negotiate(self::settings(strategy: 'open at 2%'))->hash;
        $strategyHashB = self::composer()->negotiate(self::settings(strategy: 'open at 4%'))->hash;

        self::assertNotSame($strategyHashA, $strategyHashB);

        $composer = self::composer();
        $toneHashFormal = $composer->reply(self::settings(tone: 'formal'))->hash;
        $toneHashWarm = $composer->reply(self::settings(tone: 'warm'))->hash;

        self::assertNotSame($toneHashFormal, $toneHashWarm);
        self::assertSame(
            $composer->extract()->hash,
            $composer->extract()->hash,
            'The extract prompt takes no merchant input, so its hash is constant per deploy.',
        );
    }

    public function testTheRealNegotiatePromptComposesWithAStrategySection(): void
    {
        // Against the shipped prompt files, so an edit to them is a visible
        // diff in this test rather than a silent change in agent behaviour.
        $composer = new PromptComposer(
            (string) file_get_contents(__DIR__ . '/../../../config/agents/quote-extract-agent.prompt.md'),
            (string) file_get_contents(__DIR__ . '/../../../config/agents/quote-negotiate-agent.prompt.md'),
            (string) file_get_contents(__DIR__ . '/../../../config/agents/quote-reply-agent.prompt.md'),
        );

        $composed = $composer->negotiate(self::settings(strategy: 'concede in 1% steps'))->text;

        self::assertStringStartsWith('You are a merchant\'s B2B sales agent', $composed);
        self::assertStringEndsWith("## Merchant strategy\n\nconcede in 1% steps", $composed);
        self::assertStringNotContainsString('{{tone}}', $composer->reply(self::settings(tone: 'warm'))->text);
    }
}
