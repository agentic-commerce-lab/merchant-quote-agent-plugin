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
}
