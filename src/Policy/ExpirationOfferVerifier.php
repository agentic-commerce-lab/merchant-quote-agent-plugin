<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Ported from `verifyExpiration` in the retired TS agent (policy/offer-verification.ts).
 */
final class ExpirationOfferVerifier
{
    private const int DAY_IN_SECONDS = 86_400;

    /** @return list<string> */
    public function verify(QuoteSnapshot $final, QuoteLimits $limits, \DateTimeImmutable $now): array
    {
        $expirationDate = $final->lifecycle->expirationDate;
        if ($expirationDate === null) {
            return [];
        }

        $expiresAt = (new \DateTimeImmutable($expirationDate))->getTimestamp();
        $shown = substr($expirationDate, offset: 0, length: 10);

        // #57. The window used to be one-sided: it asked only whether the
        // agent had been too generous. A validityDays of 0 made OfferApplier
        // write `+0 days`, an expiry of now, which sits comfortably inside
        // "no later than now + 1 day" — so the one gate whose job is to keep
        // an unauthorised offer unsent signed off on an offer that had
        // already expired.
        //
        // At or before `now`, with no grace period, is the right threshold for
        // how this value arrives: SnapshotAdapter::toPolicy() formats it as
        // 'Y-m-d', so the smallest legal validity of 1 reads back as tomorrow
        // at midnight and clears this bound by hours, while a validity of 0
        // reads back as today at midnight and fails it for every write after
        // 00:00. A grace period would have to be sub-daily to change any of
        // that, and would only blur the boundary.
        if ($expiresAt <= $now->getTimestamp()) {
            return [sprintf('offer validity %s is not in the future', $shown)];
        }

        // One extra day of slack for timezone conversion of date-only asks.
        $latestAllowed = $now->getTimestamp() + (($limits->validityDays + 1) * self::DAY_IN_SECONDS);
        if ($expiresAt <= $latestAllowed) {
            return [];
        }

        return [sprintf('offer validity %s exceeds the %d-day window', $shown, $limits->validityDays)];
    }
}
