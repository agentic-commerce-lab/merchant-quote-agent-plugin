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
 * unitPriceNet * quantity over the positive lines to normalise gross-vs-net
 * price space, so prices
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
     * The round's snapshot re-anchored on the original prices: the baseline
     * total and lines, carrying the LIVE asks and identities, everything else
     * from now. The only reference builder — authorize and verify both read
     * this (#54); two builders was what let the verify side fall behind the
     * authorize side, so `asReferenceSnapshot()` is gone.
     *
     * Live quote 1101: the ask was measured against the previous round's
     * reduced price (7.10 of 7.19 = 1.25%) while the checks bound the offer
     * against the original (7.10 of 7.99 = 11.14%), so the model was capped
     * at 1.25%, obeyed, and was refused for it. The ceiling, the brief, the
     * mirror and the checks all read this one snapshot now.
     *
     * Extends on every call (#54), not just at the stamping sites:
     * ServiceQuoteHandler hands the pipeline the pass-start snapshot, whose
     * custom fields predate the extension that pass just wrote, so without
     * this the proposer and the applier would anchor on different numbers —
     * one extended, one not.
     *
     * Known limitation, deliberately not fixed here: `totalNet` only grows for
     * a line ADDED since the stamp. It is not adjusted for a quantity
     * reduction or a line removal since — DiscountTotalViolation reads the
     * gap between this total and the final one as discount, so a buyer who
     * halves a quantity or drops a line shrinks the total structurally and
     * the check reads that as a concession. See the design doc's "Known
     * limitations" section. The mirror case — a line added mid-negotiation —
     * is closed for every reader that goes through this method (directly, or
     * via SnapshotAdapter::anchored()): the baseline total now grows with it,
     * so it no longer pulls the measured discount down. A caller that reads
     * a stored `QuoteBaselineLines::$totalNet` directly instead bypasses that
     * and stays stale.
     *
     * NetFactor::of() reads the same ratio on the returned snapshot as on the
     * stored baseline: `extendedWith()` grows `totalNet` by exactly the value
     * its added lines contribute, so the denominator and the numerator move
     * together. That only holds if `linesMergedWith()` cannot add a LINE the
     * total did not count — an unknown live line filtered out of the total
     * for being negative has to be filtered out of the merged lines the same
     * way, or the denominator grows alone and every reference price scales
     * up with it.
     */
    public function anchor(PolicySnapshot $live): PolicySnapshot
    {
        $extended = $this->extendedWith($live->lines);

        return new PolicySnapshot(
            currencyIso: $live->currencyIso,
            totalNet: $extended->totalNet,
            lines: $extended->linesMergedWith($live->lines),
            lifecycle: $live->lifecycle,
        );
    }

    /**
     * This baseline plus every live line it does not know, each at the price
     * it carries right now (#54).
     *
     * `linesMergedWith()` already treats an unknown line's current price as
     * its own baseline, and is right to: the line has had no agent concession
     * behind it. That only held for the round the line appeared, because the
     * merge was thrown away and recomputed the next round from a price round
     * N had already cut — the per-line compounding #49 closed, reopened for
     * exactly those lines. Persisting the merge is what makes the judgement
     * hold on every later round.
     *
     * Negative lines are never taken. Shopware generates one for a quote-wide
     * percentage discount, so stamping it would fold the agent's own round-one
     * concession into the anchor round two is measured against.
     * LineNetViolation skips them for the same reason.
     *
     * Returns `$this` when there is nothing to add, so a caller can ask
     * whether anything changed with `===`.
     *
     * The scan and the scaling arithmetic live in BaselineExtension, split
     * out to keep this class within the complexity gate.
     *
     * @param list<PolicyLine> $live
     */
    public function extendedWith(array $live): self
    {
        $added = BaselineExtension::unknownLines($this->lines, $live);

        if ($added === []) {
            return $this;
        }

        $value = BaselineExtension::scaledValue($this->lines, $this->totalNet, $added);

        return new self($this->totalNet + $value, [...$this->lines, ...$added]);
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
     * An unknown current line is appended through BaselineExtension's own
     * filter — the same one extendedWith() uses to grow the total — rather
     * than a plain "not known" check, so a live negative line never enters
     * the merged lines that extendedWith() left out of totalNet. Counting it
     * in one and not the other is what let NetFactor::of() read an inflated
     * ratio for a Shopware discount line unknown to the baseline (CRITICAL,
     * caught by testALiveNegativeLineDoesNotInflateTheNetFactor).
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

        $merged = [];

        foreach ($this->lines as $line) {
            $match = $currentById[$line->lineItemId()] ?? null;
            $merged[] = $match === null ? $line : $line->asOriginalOf($match);
        }

        return [...$merged, ...BaselineExtension::unknownLines($this->lines, $current)];
    }
}
