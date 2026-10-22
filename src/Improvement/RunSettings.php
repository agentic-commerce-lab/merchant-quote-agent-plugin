<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;

/**
 * The two settings readings one channel's nightly run needs: the improvement
 * loop's own configuration, and the agent's production settings the control
 * arm replays with. Bundled so RunSettingsResolver::resolve() returns one
 * thing and ImprovementRunner never has to ask twice.
 */
final readonly class RunSettings
{
    public function __construct(
        public ImprovementSettings $improvement,
        public QuoteAgentSettings $agent,
    ) {}
}
