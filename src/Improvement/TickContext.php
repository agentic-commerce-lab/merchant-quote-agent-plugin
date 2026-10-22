<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * The three values every group evaluated in one nightly tick shares, grouped
 * so ImprovementRunner's per-group methods stay under the parameter-count
 * gate: the channel, the window every group's decisions were read from (the
 * window is per CHANNEL, not per strategy -- see DecisionHarvest), and the
 * moment the tick started.
 */
final readonly class TickContext
{
    public function __construct(
        public ?string $salesChannelId,
        public ImprovementWindow $window,
        public \DateTimeImmutable $now,
    ) {}
}
