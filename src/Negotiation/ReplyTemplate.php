<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The deterministic reply. Two jobs: it is the whole reply in rules-only mode,
 * and it is the fallback whenever the model's rewording drops a fact.
 *
 * It cannot hallucinate, which is why it is the safe side of that choice — a
 * plainer sentence reaching a buyer is strictly better than a fluent one with
 * the wrong number in it.
 */
final class ReplyTemplate
{
    private function __construct() {}

    public static function compose(float $discountPercent, \DateTimeImmutable $validUntil): string
    {
        return sprintf(
            'We can offer %s%% off this quote. The offer is valid until %s.',
            self::percent($discountPercent),
            $validUntil->format('Y-m-d'),
        );
    }

    /** The figure the guard looks for, formatted once so both sides agree. */
    public static function percent(float $discountPercent): string
    {
        return rtrim(rtrim(number_format($discountPercent, 2, '.', ''), '0'), '.');
    }

    /** The prompt's own rule: a rewording must keep the discount and the date exactly as given. */
    public static function keepsTheFacts(string $reworded, float $discountPercent, \DateTimeImmutable $validUntil): bool
    {
        return (
            $reworded !== ''
            && str_contains($reworded, self::percent($discountPercent))
            && str_contains($reworded, $validUntil->format('Y-m-d'))
        );
    }
}
