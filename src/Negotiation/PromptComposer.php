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
        $tone = $settings->policy->price->replyTone;
        $tone = $tone === null || trim($tone) === '' ? self::NEUTRAL_TONE : trim($tone);

        return new ComposedPrompt(str_replace(self::TONE_PLACEHOLDER, $tone, $this->replyBase));
    }
}
