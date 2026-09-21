<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Config\ModelAccess;

/**
 * Everything one nightly run needs from the administration.
 *
 * Clamped rather than trusted, for the reason the design spec's §4 gives: an
 * out-of-range value does not fail loudly, it produces a number in the admin
 * that reads as a finding and is a lie. A sample of 5000 would also bill the
 * merchant for a night they never asked for.
 */
final readonly class ImprovementSettings
{
    public int $sampleSize;

    public int $candidates;

    public function __construct(
        public bool $enabled,
        public ImprovementCadence $cadence,
        int $sampleSize,
        int $candidates,
        public ModelAccess $llm,
    ) {
        $this->sampleSize = max(1, min(100, $sampleSize));
        $this->candidates = max(1, min(4, $candidates));
    }

    /** 1 judge call plus one replay per arm per sampled decision. */
    public function callBudget(): int
    {
        return 1 + ($this->sampleSize * (1 + $this->candidates));
    }
}
