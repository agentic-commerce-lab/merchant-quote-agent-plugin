<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;

/**
 * The benchmark's real synthetic buyer: a model plays `$persona` and reacts to
 * the agent's last move, answering under `BuyerAnswer`'s generated schema
 * through the same `ModelPlatform::object()` call the agent side uses to read
 * a buyer's actual free text (see AskInterpreter). `ScriptedBuyer` is the free,
 * deterministic regression gate; this is the one that costs a model call.
 *
 * `ModelUnavailable` is left to propagate uncaught. A buyer that cannot answer
 * is a failed bench cell, not a walk -- recording a walk the buyer never chose
 * would make a provider outage look like a negotiating outcome.
 */
final readonly class LlmBuyer implements SyntheticBuyer
{
    public function __construct(
        private ModelPlatform $platform,
        private ModelAccess $access,
        private string $persona,
    ) {}

    /** @throws ModelUnavailable */
    public function respond(QuoteSnapshot $before, QuoteSnapshot $after, string $agentReply, int $round): BuyerMove
    {
        $answer = $this->platform->object(
            $this->access,
            self::systemPrompt($this->persona),
            self::userPrompt($before, $after, $agentReply, $round),
            BuyerAnswer::class,
        );

        return match ($answer->kind) {
            BuyerMoveKind::Accept => BuyerMove::accept(),
            BuyerMoveKind::Walk => BuyerMove::walk(),
            BuyerMoveKind::Counter => self::counter($answer->comment),
        };
    }

    /**
     * An empty counter is not a lesser answer, it is an invalid one: the
     * interpreter reads no text as no ask at all and the pass records
     * `nothing_to_do`, so the round would be silently lost rather than scored
     * as the counter it actually was.
     */
    private static function counter(string $comment): BuyerMove
    {
        if ($comment === '') {
            throw new \RuntimeException(
                'The buyer countered without a comment; the interpreter would read that as no ask at all.',
            );
        }

        return BuyerMove::counter($comment);
    }

    /**
     * The persona IS the system prompt, plus a standing instruction never to
     * reveal it is synthetic: a buyer that announces itself changes what the
     * agent replies, which would make the transcript measure something other
     * than the negotiation it is supposed to be scoring.
     */
    private static function systemPrompt(string $persona): string
    {
        return (
            $persona
            . "\n\nYou are role-playing a real buyer in a live price negotiation with a sales agent. Never say, "
            . 'imply, or admit that you are an AI, a language model, a simulation, or a test buyer, even if asked '
            . 'directly -- stay fully in character at all times.'
        );
    }

    private static function userPrompt(
        QuoteSnapshot $before,
        QuoteSnapshot $after,
        string $agentReply,
        int $round,
    ): string {
        return \sprintf(
            "Round: %d\nOpening total: %.2f\nCurrent total: %.2f\nAgent's reply: %s",
            $round,
            $before->totals->totalNet,
            $after->totals->totalNet,
            $agentReply,
        );
    }
}
