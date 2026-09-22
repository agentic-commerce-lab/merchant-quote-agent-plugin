<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * What the ladder decided: the prompt to send, and which rung decided it.
 *
 * The source travels with the strategy rather than being recomputed later,
 * because by the time a decision row is written the rows the ladder read may
 * already have changed.
 */
final readonly class AssignedStrategy
{
    public function __construct(
        public ResolvedStrategy $strategy,
        public StrategyAssignmentSource $source,
    ) {}
}
