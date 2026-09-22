<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * What `OfferRound` reports to `ReplyComposer` for this pass, worked out in
 * one place so `OfferRound::play()` itself only has one branch to take on
 * the result -- split out the same way `DiscountTotalViolation` was split
 * from `TotalsOfferVerifier`, to keep the complexity this decision carries
 * off `OfferRound`'s own budget.
 *
 * Three outcomes, not two: a hold (#175), a genuine reduction, or a
 * disagreement (#174) -- `ReplyTemplate::reduction()` throwing
 * `NegativeReduction` because the figure it was asked to describe would be an
 * increase. That last one can only mean `OfferApplier`'s
 * never-raise check missed a write that already landed (there is no
 * rollback), and `OfferRound` must escalate instead of composing a reply
 * with it -- never let it propagate to the caller. See `NegativeReduction`'s
 * own docblock for why a worker retry against that state is worse than the
 * clamped `0%` this whole issue replaces.
 */
final class ReductionForPass
{
    private function __construct() {}

    /**
     * @param float $beforeNet the pass-start baseline total `reduction()` is
     *                         measured from (`SnapshotAdapter::anchored()`)
     * @param float $afterNet  what the database reports after the write
     * @param bool $grantedThisPass whether this pass's own write actually
     *                              moved the total (`OfferApplier`'s
     *                              pre-write read vs. its post-write one) --
     *                              never the baseline, which can differ from
     *                              both
     *
     * @return array{0: ?float, 1: bool} the reduction percent (null for a
     *                                    hold), and whether the figure
     *                                    disagreed with the write and this
     *                                    pass must escalate instead of reply
     */
    public static function of(float $beforeNet, float $afterNet, bool $grantedThisPass): array
    {
        if (!$grantedThisPass) {
            return [null, false];
        }

        try {
            $percent = ReplyTemplate::reduction($beforeNet, $afterNet);
        } catch (NegativeReduction) {
            return [null, true];
        }

        // A hold is what the SENTENCE would say, not only what the write did.
        // `$grantedThisPass` asks whether the total moved at all, which is a
        // different question from whether the figure announcing it says
        // anything: `percent()` prints two decimals, so 0.50 EUR off a
        // 34456.73 quote -- a per-line cut on one small line of a large
        // quote, the very size #175 was filed from -- is a real write that
        // still prints `0`. Measured on the percent STRING rather than on the
        // float, because the string is the thing the buyer reads and the
        // thing `RewordingGuard` matches; anything else lets the two disagree
        // about the same pass.
        //
        // The audit trail does not lose the figure: `GrantedDiscount` records
        // it to four decimals from the same two totals, independently of what
        // is said out loud.
        return [ReplyTemplate::percent($percent) === '0' ? null : $percent, false];
    }
}
