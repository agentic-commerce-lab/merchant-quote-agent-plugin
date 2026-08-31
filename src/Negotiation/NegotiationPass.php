<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/**
 * What one pass did and which prompts produced it — the payload of the single
 * structured log line every pass ends with.
 *
 * A hash is null where the stage did not run: no new ask means no extract call,
 * an out-of-authority ask never reaches negotiate, and a template-written reply
 * has no reply prompt behind it. #19 reads these events; #22 needs the hashes
 * to attribute an outcome to the prompt versions that produced it.
 *
 * `escalationReason` is null for every non-escalating outcome, and carries the
 * reason on an escalating one — OfferRound::escalated() is the single funnel
 * all six of its callers go through, so this is where they hand it off to the
 * audit trail without each needing a recorder of its own.
 */
final readonly class NegotiationPass
{
    public function __construct(
        public NegotiationOutcome $outcome,
        public ?string $extractHash = null,
        public ?string $negotiateHash = null,
        public ?string $replyHash = null,
        public ?QuoteEscalationReason $escalationReason = null,
    ) {}
}
