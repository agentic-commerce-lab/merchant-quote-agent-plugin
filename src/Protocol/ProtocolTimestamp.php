<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol;

/**
 * The one timestamp format A2CN accepts: `2026-09-05T10:00:00Z`, UTC, second
 * resolution.
 *
 * The counterparty's act schema pins
 * `^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$` for `timestamp`
 * and `expires_at`, which `\DATE_ATOM` fails: it writes the offset form,
 * `2026-09-05T10:00:00+00:00`. Every timestamp this module publishes goes
 * through here instead, so an act and the record that carries it never disagree
 * about how a time is written.
 *
 * The conversion to UTC is the load-bearing half, not the format string: a
 * `+02:00` time rendered with a literal `Z` would parse as a wrong instant two
 * hours earlier, and it would parse cleanly — a silent lie is worse than a
 * schema failure.
 *
 * It lives in the module root because it belongs to no one part of it: acts,
 * violations, receipts, records, mandates and the discovery document all state
 * times, and none of them owns the format.
 */
final class ProtocolTimestamp
{
    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * The same rule as FORMAT, spelled as the counterparty's act schema
     * spells it. They live side by side deliberately: a change to one that
     * is not made to the other is a writer and a reader that disagree about
     * what a timestamp is.
     */
    public const PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/';

    private function __construct() {}

    public static function matches(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function of(\DateTimeImmutable $at): string
    {
        // gmdate() over setTimezone(new DateTimeZone('UTC'))->format(): the
        // constructor form declares `@throws DateInvalidTimeZoneException`
        // for an unreachable case ('UTC' is always a valid identifier), and
        // that checked exception would otherwise force every one of this
        // method's callers across the module to declare it too. gmdate()
        // renders a Unix timestamp in UTC directly and cannot throw.
        return gmdate(self::FORMAT, $at->getTimestamp());
    }

    /** The wall-clock reading, for the paths that have no injected clock. */
    public static function now(): string
    {
        return self::of(new \DateTimeImmutable());
    }
}
