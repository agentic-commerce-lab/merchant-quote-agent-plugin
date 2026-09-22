<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

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
     */
    public function __construct(
        public bool $verified,
        public array $violations,
        public QuoteSnapshot $after,
        public float $beforeNet,
    ) {}
}
