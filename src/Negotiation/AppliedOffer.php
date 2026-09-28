<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;

/** What the database says after we wrote, and whether it agrees with us. */
final readonly class AppliedOffer
{
    /**
     * @param list<string> $violations
     * @param float $beforeNet the total the quote showed right before this
     *                         pass wrote anything -- the pre-write read
     *                         `apply()` takes immediately before the write,
     *                         never the baseline. #174/#175: this is what
     *                         tells OfferRound whether the pass actually
     *                         granted anything, so the reply can say so
     *                         truthfully instead of reporting a baseline
     *                         percentage for a pass that changed nothing.
     * @param bool $written false when the offer failed verification on its
     *                      predicted result and nothing was written; `$after`
     *                      is then the pre-write read
     */
    public function __construct(
        public bool $verified,
        public array $violations,
        public QuoteSnapshot $after,
        public float $beforeNet,
        public bool $written = true,
    ) {}

    /**
     * Why an unverified offer escalates. One refused on its predicted result
     * wrote nothing: the policy rejected the proposal, and the database never
     * disagreed with anything.
     */
    public function escalationReason(): QuoteEscalationReason
    {
        return $this->written ? QuoteEscalationReason::VerificationFailed : QuoteEscalationReason::ProposalRejected;
    }
}
