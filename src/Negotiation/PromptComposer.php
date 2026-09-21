<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;

/**
 * Composes the three system prompts. The base texts are handed in as strings
 * by the container, never read from disk here — that is what keeps this
 * namespace free of Shopware and of any path assumption.
 *
 * The merchant's strategy tunes tone and posture and CANNOT move a cap: it
 * lands in a delimited section below the base instructions, and OfferAuthorizer
 * rejects anything outside authority regardless of what the prompt asked for.
 */
final readonly class PromptComposer
{
    private const STRATEGY_HEADING = '## Merchant strategy';

    private const TONE_PLACEHOLDER = '{{tone}}';

    private const NEUTRAL_TONE = 'neutral and professional';

    /**
     * ponytail: a hard character ceiling, not a smarter summariser. Every
     * built-in strategy opens with a short "Act as ..." sentence well under
     * this, so it never fires for them; it only catches a custom strategy
     * written as one long run-on sentence. Revisit if that turns out to be
     * common rather than the rare case this guards against.
     */
    private const MAX_TONE_LENGTH = 160;

    public function __construct(
        private string $extractBase,
        private string $negotiateBase,
        private string $replyBase,
    ) {}

    public function extract(): ComposedPrompt
    {
        return new ComposedPrompt($this->extractBase);
    }

    public function negotiate(QuoteAgentSettings $settings): ComposedPrompt
    {
        $strategy = $settings->strategyPrompt;

        if ($strategy === null || trim($strategy) === '') {
            return new ComposedPrompt($this->negotiateBase);
        }

        return new ComposedPrompt($this->negotiateBase . "\n\n" . self::STRATEGY_HEADING . "\n\n" . trim($strategy));
    }

    public function reply(QuoteAgentSettings $settings): ComposedPrompt
    {
        // The reply's tone is DERIVED from the same strategy field that tunes
        // the negotiation prompt; there is no separate tone setting. Issue
        // #168: the whole negotiating brief used to be pasted into the tone
        // slot verbatim, which reads as a posture, not a tone, in a prompt
        // whose only other instruction is "reword, add nothing" -- toneFrom()
        // keeps only the opening sentence. The reply model can still only
        // reword -- RewordingGuard::unsafeBecause() sends the template
        // instead if a figure moved or a new one appeared -- so a strategy
        // that talks about percentages cannot price anything from here, and
        // one that talks about extras cannot promise anything.
        $strategy = $settings->strategyPrompt;
        $tone = $strategy === null || trim($strategy) === '' ? self::NEUTRAL_TONE : self::toneFrom($strategy);

        return new ComposedPrompt(str_replace(self::TONE_PLACEHOLDER, $tone, $this->replyBase));
    }

    /**
     * The strategy's opening sentence, not its whole negotiating brief.
     *
     * Every built-in strategy (`BuiltInStrategies`) opens with a short "Act
     * as a disciplined B2B seller." / "Act as a relationship-minded B2B
     * seller." / "Prioritize a fast, clear path to agreement..." sentence
     * before the tactical paragraphs that follow -- taking just that first
     * sentence produces a sane tone for all three without a lookup table, and
     * the same rule works for a merchant's own strategy text, which is what
     * keeps the tone "merchant-editable": it is however they choose to open
     * their strategy.
     */
    private static function toneFrom(string $strategy): string
    {
        $strategy = trim($strategy);

        $end = \strlen($strategy);
        $match = [];
        if (preg_match('/[.!?]/', $strategy, $match, PREG_OFFSET_CAPTURE) === 1) {
            /** @var array{0: array{0: string, 1: int}} $match */
            $end = $match[0][1] + 1;
        }
        $tone = trim(substr($strategy, 0, $end));

        if (mb_strlen($tone) > self::MAX_TONE_LENGTH) {
            $tone = rtrim(mb_substr($tone, 0, self::MAX_TONE_LENGTH)) . '…';
        }

        return $tone;
    }
}
