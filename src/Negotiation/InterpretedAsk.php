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
}
