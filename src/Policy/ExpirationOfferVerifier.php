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

        // One extra day of slack for timezone conversion of date-only asks.
        $latestAllowed = $now->getTimestamp() + (($limits->validityDays + 1) * self::DAY_IN_SECONDS);
        if ((new \DateTimeImmutable($expirationDate))->getTimestamp() <= $latestAllowed) {
            return [];
        }

        return [sprintf(
            'offer validity %s exceeds the %d-day window',
            substr($expirationDate, offset: 0, length: 10),
            $limits->validityDays,
        )];
    }
}
