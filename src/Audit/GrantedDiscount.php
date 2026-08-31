<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * What the quote actually came down by, measured on the same two database
 * totals ReplyTemplate::reduction() tells the buyer — never on the offer we
 * asked for, which is null for a per-line concession.
 *
 * Split out of DecisionRecorder so this branching counts against this small
 * class's complexity budget rather than DecisionRecorder's: a private helper
 * would still sum into the same class-scoped total.
 */
final class GrantedDiscount
{
    private function __construct() {}

    public static function of(?float $totalNetBefore, float $totalNetAfter): ?float
    {
        if ($totalNetBefore === null || $totalNetBefore <= 0.0) {
            return null;
        }

        return round((($totalNetBefore - $totalNetAfter) / $totalNetBefore) * 100, precision: 4);
    }
}
