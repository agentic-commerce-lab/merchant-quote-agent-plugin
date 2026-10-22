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
 *
 * $sampleSize and $candidates are per STRATEGY, not per channel: the window
 * is grouped by lineage before either is applied (see DecisionHarvest,
 * ImprovementRunner), so a channel running two strategies pays for the judge
 * and replay calls twice in one night, once per lineage. This class has no
 * visibility into how many strategies a channel is running -- that is a
 * runtime fact of the window, not a config value -- so it cannot clamp that
 * multiplier itself; config.xml's help text says so instead.
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
}
