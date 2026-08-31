<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * Adds a model call's token count onto the draft's running total — unless
 * the provider sent no `usage` block, in which case the running total must
 * stay exactly what it already was, not fold a null into a silent zero.
 *
 * Split out of DecisionRecorder so this branching counts against this small
 * class's complexity budget rather than DecisionRecorder's: a private helper
 * would still sum into the same class-scoped total.
 */
final class TokenTally
{
    private function __construct() {}

    public static function add(?int $runningTotal, ?int $increment): ?int
    {
        if ($increment === null) {
            return $runningTotal;
        }

        return (int) $runningTotal + $increment;
    }
}
