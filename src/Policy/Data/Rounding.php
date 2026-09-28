<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * What rounding control did to one offer, for the `rounding` trace event.
 * `unrounded` and `rounded` are percentages in discount_percent mode and
 * buyer-facing totals in quote_total mode.
 */
final readonly class Rounding
{
    public function __construct(
        public RoundingMode $mode,
        public float $step,
        public float $unrounded,
        public float $rounded,
        /** Null when the rounded figure is the one written. */
        public ?RoundingSkip $skipped,
    ) {}

    /** The figure that goes out: the rounded one, unless a rule skipped it. */
    public function written(): float
    {
        return $this->skipped === null ? $this->rounded : $this->unrounded;
    }
}
