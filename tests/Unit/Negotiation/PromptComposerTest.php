<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Strategy\BuiltInStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @mago-expect lint:too-many-methods
 * One test per guardrail PromptComposer enforces (the merchant section, the
 * cap, the shipped-prompt integration, and now the strategy-library path) --
 * splitting the class would hide which guardrail broke behind a file boundary.
 */
final class PromptComposerTest extends TestCase
{
    private static function composer(): PromptComposer
    {
        return new PromptComposer('EXTRACT BASE', 'NEGOTIATE BASE', 'REPLY BASE {{tone}} END');
    }

    private static function settings(?string $strategy = null): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: $strategy,
        );
    }

    public function testTheExtractPromptIsVerbatimWhenItHasNoToneSlot(): void
    {
        self::assertSame('EXTRACT BASE', self::composer()->extract(self::settings())->text);
    }

    #[DataProvider('extractToneTestCases')]
    public function testTheExtractPromptToneComesFromTheSameMerchantStrategyAsReply(
        ?string $strategy,
        string $expectedTone,
    ): void {
        // Issue #171: the extract prompt's tone slot is filled the same way
        // reply()'s is, from the same strategy field -- no second setting.
        $composer = new PromptComposer('EXTRACT {{tone}} BASE', 'NEGOTIATE BASE', 'REPLY BASE {{tone}} END');

        $composed = $composer->extract(self::settings(strategy: $strategy))->text;

        self::assertSame('EXTRACT ' . $expectedTone . ' BASE', $composed);
        self::assertStringNotContainsString('{{tone}}', $composed);
    }

    /** @return array<string, array{0: ?string, 1: string}> */
    public static function extractToneTestCases(): array
    {
        return [
            'the strategy is the tone' => ['formal, never pushy', 'formal, never pushy'],
            'no strategy gets neutral' => [null, 'neutral and professional'],
            'a blank strategy gets neutral' => ['   ', 'neutral and professional'],
        ];
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
    public function testTheReplyToneComesFromTheMerchantStrategy(?string $strategy, string $expected): void
    {
        // There is no separate reply-tone setting: the strategy field is the
        // one place a merchant states how the agent should sound.
        $composed = self::composer()->reply(self::settings(strategy: $strategy))->text;

        self::assertSame($expected, $composed);
        self::assertStringNotContainsString('{{tone}}', $composed);
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function replyToneTestCases(): array
    {
        return [
            'the strategy is the tone' => ['formal, never pushy', 'REPLY BASE formal, never pushy END'],
            'no strategy gets neutral' => [null, 'REPLY BASE neutral and professional END'],
            'a blank strategy gets neutral' => ['   ', 'REPLY BASE neutral and professional END'],
        ];
    }

    /**
     * Issue #168: the reply prompt's tone slot used to receive the whole
     * negotiating brief verbatim -- a posture, not a tone, in a prompt whose
     * only other instruction is "reword, add nothing". Each built-in
     * strategy's tone must now be its own short opening sentence, not the
     * full multi-paragraph prompt `BuiltInStrategiesTest` pins byte for byte.
     */
    #[DataProvider('builtInTones')]
    public function testABuiltInStrategyReducesToItsOpeningSentenceAsTone(string $id, string $expectedTone): void
    {
        $prompt = BuiltInStrategies::all()[$id]['prompt'];

        $composed = self::composer()->reply(self::settings(strategy: $prompt))->text;

        self::assertSame('REPLY BASE ' . $expectedTone . ' END', $composed);
        self::assertStringNotContainsString(
            'Do not invent commitments',
            $composed,
            'The tactical paragraphs are posture, not tone, and must not reach the reply prompt.',
        );
    }

    /** @return array<string, array{string, string}> */
    public static function builtInTones(): array
    {
        return [
            'margin defender' => [BuiltInStrategies::MARGIN_DEFENDER, 'Act as a disciplined B2B seller.'],
            'fast close' => [
                BuiltInStrategies::FAST_CLOSE,
                'Prioritize a fast, clear path to agreement for routine B2B quote requests.',
            ],
            'relationship builder' => [
                BuiltInStrategies::RELATIONSHIP_BUILDER,
                'Act as a relationship-minded B2B seller.',
            ],
        ];
    }

    /**
     * A custom strategy with no sentence terminator at all -- the shape the
     * existing `replyToneTestCases()` table already covers ('formal, never
     * pushy') -- is short enough to use whole. This pins the other edge: a
     * custom strategy written as one very long run-on sentence still yields a
     * short tone rather than pasting the whole thing.
     */
    public function testAnUnpunctuatedCustomStrategyLongerThanTheCeilingIsTruncated(): void
    {
        $longSentence = 'Be warm and flexible and accommodating and generous ' . str_repeat('and patient ', 20);

        $composed = self::composer()->reply(self::settings(strategy: $longSentence))->text;
        $tone = substr($composed, \strlen('REPLY BASE '), -\strlen(' END'));

        self::assertLessThanOrEqual(161, mb_strlen($tone), 'The 160-character ceiling plus its ellipsis.');
        self::assertStringEndsWith('…', $tone);
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
        $toneHashFormal = $composer->reply(self::settings(strategy: 'formal'))->hash;
        $toneHashWarm = $composer->reply(self::settings(strategy: 'warm'))->hash;

        self::assertNotSame($toneHashFormal, $toneHashWarm);

        // Issue #171: the extract prompt now carries the same per-merchant
        // tone, so its hash varies too -- extract_prompt_hash was never relied
        // on to be stable across merchants (negotiatePromptHash and
        // replyPromptHash already are not).
        $toneableExtractComposer = new PromptComposer('EXTRACT {{tone}} BASE', 'NEGOTIATE BASE', 'REPLY BASE');
        $extractHashA = $toneableExtractComposer->extract(self::settings(strategy: 'formal'))->hash;
        $extractHashB = $toneableExtractComposer->extract(self::settings(strategy: 'warm'))->hash;

        self::assertNotSame($extractHashA, $extractHashB);
        self::assertSame(
            $toneableExtractComposer->extract(self::settings())->hash,
            $toneableExtractComposer->extract(self::settings())->hash,
            'Same settings, same composed text, same hash.',
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
        self::assertStringNotContainsString('{{tone}}', $composer->reply(self::settings(strategy: 'warm'))->text);
        self::assertStringNotContainsString('{{tone}}', $composer->extract(self::settings(strategy: 'warm'))->text);
    }

    public function testAStrategyResolvedFromAVersionComposesLikeTypedTextDid(): void
    {
        // What QuoteAgentSettingsReader now builds: the prompt came from a
        // StrategyVersion rather than from a textarea, and PromptComposer
        // cannot tell the difference -- it reads $settings->strategyPrompt
        // either way. That indifference is what keeps every guardrail test
        // above meaningful after the library lands.
        $settings = new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            llm: new ModelAccess('sk-test', 'https://api.example.com/v1', 'gpt-4o-mini'),
            strategyPrompt: 'open at 2%',
            strategyVersionId: 'feedfacefeedfacefeedfacefeedface',
        );

        self::assertSame(
            "NEGOTIATE BASE\n\n## Merchant strategy\n\nopen at 2%",
            self::composer()->negotiate($settings)->text,
        );
        self::assertSame('REPLY BASE open at 2% END', self::composer()->reply($settings)->text);
    }
}
