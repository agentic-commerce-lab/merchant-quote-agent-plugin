<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * Which rung of the assignment ladder chose the strategy this pass sent.
 *
 * Recorded on every decision row beside `strategy_version_id`, which it
 * explains. It is the only way a merchant can see that a rule they configured
 * never actually matched: the negotiation looks identical either way, because
 * the ladder falls through to the next rung rather than escalating.
 *
 * `Config` is the sales-channel configuration key -- the only mechanism that
 * existed before the ladder, and still the bottom rung.
 */
enum StrategyAssignmentSource: string
{
    case Pin = 'pin';
    case Rule = 'rule';
    case Split = 'split';
    case Config = 'config';
}
