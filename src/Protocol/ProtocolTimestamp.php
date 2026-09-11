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

    /**
     * Shape AND reality: `strtotime()` alone accepts more than PATTERN's
     * digits promise, because it rolls an out-of-range field into the next
     * one instead of refusing it — `2026-02-30T00:00:00Z` silently becomes
     * March 2nd, and `23:59:60Z` silently becomes the next minute. Both
     * checks TimestampFormatCheck and TimestampMonotonicityCheck exist to
     * order acts by parsing this string with `strtotime()`; a value that
     * rolls is exactly the input that would otherwise slip past PATTERN,
     * parse to *some* instant, and let a counterparty opt out of ordering
     * entirely by writing a date that does not exist.
     *
     * The round-trip is the guard: render the parsed instant back through
     * FORMAT and require it to reproduce $value byte-for-byte. A rolled
     * value never survives that, because it parses to a DIFFERENT instant
     * than the one its own digits named.
     *
     * A leap second (`23:59:60Z`) is refused by the same round-trip,
     * deliberately: it is not an instant `strtotime()`/`gmdate()` can
     * represent, so there is no format string it could round-trip through,
     * and no A2CN implementation emits one.
     */
    public static function matches(string $value): bool
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            return false;
        }

        $instant = strtotime($value);

        return $instant !== false && gmdate(self::FORMAT, $instant) === $value;
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
