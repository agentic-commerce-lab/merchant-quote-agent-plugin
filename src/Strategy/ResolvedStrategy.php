<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * What a selected strategy resolves to for one negotiation: the prompt to send,
 * and the id of the version it came from, to record on the decision.
 *
 * No name. It is reachable by association from the version and nothing at
 * runtime reads it -- the administration resolves it when it renders a
 * decision.
 */
final readonly class ResolvedStrategy
{
    public function __construct(
        public string $versionId,
        public string $prompt,
    ) {}
}
