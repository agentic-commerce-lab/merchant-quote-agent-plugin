<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * Which slice of a weighted split a company falls into, from the company id
 * alone.
 *
 * Deterministic and sticky on purpose. A re-rolled bucket would hand the same
 * buyer a different negotiating personality on their next quote, and would
 * contaminate the comparison the split exists to produce with variance that is
 * within one company rather than between two strategies.
 *
 * Hashed on the COMPANY -- QuoteIdentity::$customerId is the B2B company, not
 * a person -- so every employee and every organization unit of an account
 * meets the same posture.
 *
 * The sales channel is in the hash so a company trading on two channels can
 * land in different arms on each, which is what per-channel weights imply.
 *
 * 10000 buckets rather than 100: weights need not sum to 100, and the
 * resolver scales a bucket onto whatever they do sum to. Four digits keep the
 * rounding error below a tenth of a percent for any realistic weight set.
 *
 * sha1, not crc32: the output has to be stable across PHP versions and
 * platforms forever (see SplitBucketTest), and it is not a security boundary.
 */
final class SplitBucket
{
    public static function of(string $customerId, string $salesChannelId): int
    {
        $digest = sha1($customerId . ':' . $salesChannelId);

        return (int) (hexdec(substr($digest, 0, 8)) % 10000);
    }
}
