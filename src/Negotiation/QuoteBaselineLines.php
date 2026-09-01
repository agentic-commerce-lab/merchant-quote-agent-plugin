<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;

/**
 * The quote's prices as the agent first found them (#49).
 *
 * Carries the total as well as the lines because the verifier reads all three
 * together: NetFactor::of() divides totalNet by the sum of
 * unitPriceNet * quantity to normalise gross-vs-net price space, so prices
 * without their quantities and total would produce a meaningless ratio.
 */
final readonly class QuoteBaselineLines
{
    /** @param list<PolicyLine> $lines */
    public function __construct(
        public float $totalNet,
        public array $lines,
    ) {}

    /**
     * Prices from the baseline, everything else from now. Currency, state and
     * expiry are not price history, and a stored copy of them would be a
     * second source of truth that goes stale.
     *
     * Known limitation, deliberately not fixed here: `totalNet` is the
     * baseline's stored total, unadjusted for a quantity reduction or a line
     * removal since. DiscountTotalViolation reads the gap between this total
     * and the final one as discount, so a buyer who halves a quantity or
     * drops a line shrinks the total structurally and the check reads that as
     * a concession — see the design doc's "Known limitations" section.
     */
    public function asReferenceSnapshot(PolicySnapshot $live): PolicySnapshot
    {
        return new PolicySnapshot(
            currencyIso: $live->currencyIso,
            totalNet: $this->totalNet,
            lines: $this->lines,
            lifecycle: $live->lifecycle,
        );
    }

    /**
     * The baseline's lines, plus any current line it does not know about.
     *
     * A line added mid-negotiation has had no agent concession yet, so there
     * is nothing to compound and its current price IS its baseline. Without
     * this, LinePriceOfferCheck would reject it as "line … is not on this
     * quote" and escalate for no reason. A line REMOVED mid-negotiation
     * leaves a stale baseline entry that is simply never looked up.
     *
     * A baseline row matched by id also picks up the CURRENT line's label
     * here (#49 fix 4): the stored row never carries one — see
     * QuoteBaseline::stamp() — so without this, LineReferenceViolation's
     * messages fall back to a raw UUID from round two on.
     *
     * @param list<PolicyLine> $current
     *
     * @return list<PolicyLine>
     */
    public function linesMergedWith(array $current): array
    {
        $currentById = [];

        foreach ($current as $line) {
            $currentById[$line->lineItemId()] = $line;
        }

        $known = [];
        $merged = [];

        foreach ($this->lines as $line) {
            $known[$line->lineItemId()] = true;
            $match = $currentById[$line->lineItemId()] ?? null;
            $merged[] = $match === null ? $line : $line->withLabel($match->label());
        }

        foreach ($current as $line) {
            if (!isset($known[$line->lineItemId()])) {
                $merged[] = $line;
            }
        }

        return $merged;
    }
}
