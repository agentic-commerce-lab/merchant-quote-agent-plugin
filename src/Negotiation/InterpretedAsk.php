<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;

/** What the buyer asked for, and the hash of the prompt that read it. */
final readonly class InterpretedAsk
{
    public function __construct(
        public CommentInterpretation $interpretation,
        public string $promptHash,
    ) {}

    /**
     * True when the buyer asked to change WHAT is being sold rather than what
     * it costs. A line change carrying only a target PRICE is a price ask and
     * squarely in the mandate; a quantity change (different from the current line
     * item quantity), a removal or an added product is not.
     */
    public function isStructural(?QuoteSnapshot $snapshot = null): bool
    {
        if ($this->interpretation->structural->addProducts !== []) {
            return true;
        }

        $existingQuantities = [];
        if ($snapshot !== null) {
            foreach ($snapshot->content->lines as $line) {
                $existingQuantities[$line->identity->lineItemId] = $line->quantity;
            }
        }

        foreach ($this->interpretation->structural->lineChanges as $change) {
            if ($this->isLineChangeStructural($change, $existingQuantities, $snapshot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $existingQuantities
     */
    private function isLineChangeStructural(
        InterpretedLineChange $change,
        array $existingQuantities,
        ?QuoteSnapshot $snapshot,
    ): bool {
        if ($change->remove === true) {
            return true;
        }

        if ($change->quantity === null) {
            return false;
        }

        if ($snapshot === null) {
            return true;
        }

        return (
            !\array_key_exists($change->lineItemId, $existingQuantities)
            || $change->quantity !== $existingQuantities[$change->lineItemId]
        );
    }

    /**
     * True when the buyer asked for something other than a price — shipping,
     * payment terms, a bundle. Nothing carries these past the interpreter:
     * NegotiationPipeline composes only `price` into the proposal, and the
     * gateway has no way to write a delivery or payment term onto a quote.
     */
    public function hasNonPriceAsk(): bool
    {
        return $this->interpretation->negotiation?->hasAny() === true;
    }

    /**
     * True when the model asked the buyer something instead of guessing. The
     * extract prompt reserves this for asks that are clear in intent but
     * ambiguous in reference — "10% off" on a five-line quote — so answering
     * one by picking a line is exactly the wrong move.
     */
    public function needsClarification(): bool
    {
        return $this->interpretation->clarificationQuestions !== [];
    }
}
