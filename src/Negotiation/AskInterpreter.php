<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/**
 * Model call 1: the buyer's free text becomes a structured ask.
 *
 * This is the one call rules-only mode still makes. Nothing else in the plugin
 * can read a sentence — no bridge path populates a structured target price —
 * so without it the agent cannot know what was asked and could only escalate.
 * The model READS here; it never decides and never writes.
 *
 * Returns null when no buyer comment is newer than the agent's last reply. A
 * duplicate trigger then costs nothing at all.
 */
final readonly class AskInterpreter
{
    public function __construct(
        private ModelPlatform $platform,
        private PromptComposer $prompts,
        private DecisionRecorder $recorder,
    ) {}

    /** @throws ModelUnavailable */
    public function interpret(
        QuoteAgentSettings $settings,
        QuoteSnapshot $snapshot,
        BuyerConversation $conversation,
    ): ?InterpretedAsk {
        if (!$conversation->hasNewBuyerAsk()) {
            return null;
        }

        $access = $settings->llm;

        if ($access === null) {
            throw new ModelUnavailable('No model access is configured for this sales channel.');
        }

        $prompt = $this->prompts->extract();
        $interpretation = $this->platform->object(
            $access,
            $prompt->text,
            self::userPrompt($snapshot, $conversation),
            CommentInterpretation::class,
        );

        $ask = new InterpretedAsk($interpretation, $prompt->hash);
        $this->recorder->recordAsk($ask);

        return $ask;
    }

    /**
     * The shape the extract prompt states it will receive: the line items as
     * `id | label | quantity | unit price`, then the buyer's newest comment —
     * only that one, or an older round's ask is extracted a second time.
     */
    private static function userPrompt(QuoteSnapshot $snapshot, BuyerConversation $conversation): string
    {
        $lines = array_map(static fn(QuoteLineSnapshot $l): string => sprintf(
            '%s | %s | %d | %.2f',
            $l->identity->lineItemId,
            $l->identity->label ?? '',
            $l->quantity,
            $l->unitPriceNet,
        ), $snapshot->content->lines);

        return (
            "Line items:\n" . implode("\n", $lines) . "\n\nBuyer's latest comment:\n" . $conversation->newestBuyerText()
        );
    }
}
