<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

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
     * squarely in the mandate; a quantity, a removal or an added product is
     * not.
     */
    public function isStructural(): bool
    {
        if ($this->interpretation->structural->addProducts !== []) {
            return true;
        }

        foreach ($this->interpretation->structural->lineChanges as $change) {
            if ($change->quantity !== null || $change->remove === true) {
                return true;
            }
        }

        return false;
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
}
