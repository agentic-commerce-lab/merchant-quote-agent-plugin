<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * A system prompt and the hash of exactly the text that was sent. The hash is
 * recorded on the outcome so a later analysis (#22) can attribute a result to
 * the prompt that produced it — which only works if it hashes the COMPOSED
 * text, merchant strategy and tone included, not the base file.
 */
final readonly class ComposedPrompt
{
    public string $hash;

    public function __construct(
        public string $text,
    ) {
        $this->hash = hash('sha256', $text);
    }
}
