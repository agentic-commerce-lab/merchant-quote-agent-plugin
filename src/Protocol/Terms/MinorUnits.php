<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Terms;

/**
 * Money for the signed record: the single rounding rule, stated once.
 *
 * Both sides of an A2CN session must land on the same integers or the act
 * hashes diverge, so this is a rule and not a preference. Rounds HALF AWAY
 * FROM ZERO — `round()` on the magnitude, so 1.005 and -1.005 are treated
 * symmetrically, which matters because the quote discount is a negative line
 * item.
 *
 * The 1e-9 nudge corrects binary-float representation error (1.005 * 100 is
 * 100.49999999999999, not 100.5). It cannot flip a genuine value: money here
 * is never finer than 1e-4.
 *
 * ponytail: float math with a nudge. Move to integer cents end to end if a
 * rounding dispute ever actually surfaces in a record.
 */
final class MinorUnits
{
    private function __construct() {}

    /** @throws NonFiniteAmount */
    public static function from(float $amount): int
    {
        // A non-finite amount would serialize to `null` and silently diverge
        // the cross-party hash. A record that disagrees byte-for-byte with the
        // counterparty's is worse than no record, so this throws and the
        // emitter's caller carries on servicing the quote without an act.
        if (!\is_finite($amount)) {
            throw new NonFiniteAmount(\sprintf('A2CN minor units received a non-finite amount: %s', \var_export(
                $amount,
                true,
            )));
        }

        $magnitude = (int) round((abs($amount) * 100) + 1e-9);

        return $amount < 0 ? -$magnitude : $magnitude;
    }
}
