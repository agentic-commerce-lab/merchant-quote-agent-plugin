<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/**
 * "Read this field off an act that might not exist." A session that never
 * reached an offer, or a chain with no acts at all, is not an error in a
 * record — it is a record with fewer facts to report, and this is the one
 * place that null/default choice is made rather than a `?->` and `??` pair
 * repeated at every call site.
 */
final class OptionalAct
{
    private function __construct() {}

    /** @param \Closure(Act): string $read */
    public static function stringOr(?Act $act, \Closure $read, string $default): string
    {
        return $act === null ? $default : $read($act);
    }

    /** @param \Closure(Act): string $read */
    public static function stringOrNull(?Act $act, \Closure $read): ?string
    {
        return $act === null ? null : $read($act);
    }
}
