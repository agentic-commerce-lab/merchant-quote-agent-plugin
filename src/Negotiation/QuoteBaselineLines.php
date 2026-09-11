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
     * The round's snapshot re-anchored on the original prices: the baseline
     * total and lines, carrying the LIVE asks and identities, everything else
     * from now.
     *
     * Live quote 1101: the ask was measured against the previous round's
     * reduced price (7.10 of 7.19 = 1.25%) while the checks bound the offer
     * against the original (7.10 of 7.99 = 11.14%), so the model was capped
     * at 1.25%, obeyed, and was refused for it. The ceiling, the brief, the
     * mirror and the checks all read this one snapshot now.
     */
    public function anchor(PolicySnapshot $live): PolicySnapshot
    {
        return new PolicySnapshot(
            currencyIso: $live->currencyIso,
            totalNet: $this->totalNet,
            lines: $this->linesMergedWith($live->lines),
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
     * A baseline row matched by id takes the CURRENT line's identity and
     * requested price (#49 fix 4, quote 1101): the stored row is only a price
     * and a quantity — see QuoteBaseline::stamp() — so without this the
     * checks would name a raw UUID and the ceiling would see no ask at all.
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
            $merged[] = $match === null ? $line : $line->asOriginalOf($match);
        }

        foreach ($current as $line) {
            if (!isset($known[$line->lineItemId()])) {
                $merged[] = $line;
            }
        }

        return $merged;
    }
}
