<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

/**
 * Quote state → A2CN terminal session state (spec 8.2). Null means the session
 * is still live and has no record yet.
 *
 * `WITHDRAWN` and `ERROR` are never produced: the seller does not withdraw, and
 * a protocol error leaves the session live with a violation entry instead.
 */
final class SessionOutcome
{
    private function __construct() {}

    public static function for(string $state, bool $expired): ?string
    {
        if ($state === 'declined') {
            return 'REJECTED_FINAL';
        }

        if ($expired) {
            return 'TIMED_OUT';
        }

        return null;
    }
}
