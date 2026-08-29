<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * What one pass did and which prompts produced it — the payload of the single
 * structured log line every pass ends with.
 *
 * A hash is null where the stage did not run: no new ask means no extract call,
 * an out-of-authority ask never reaches negotiate, and a template-written reply
 * has no reply prompt behind it. #19 reads these events; #22 needs the hashes
 * to attribute an outcome to the prompt versions that produced it.
 */
final readonly class NegotiationPass
{
    public function __construct(
        public NegotiationOutcome $outcome,
        public ?string $extractHash = null,
        public ?string $negotiateHash = null,
        public ?string $replyHash = null,
    ) {}
}
