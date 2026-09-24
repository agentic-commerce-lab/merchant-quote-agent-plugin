<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

/** A draft is stale if buyer input or live merchant-controlled pricing changed. */
final class ReviewFingerprint
{
    private function __construct() {}

    /** @param list<string> $mirroredLineIds */
    public static function atDraft(QuoteSnapshot $serviced, QuoteSnapshot $live, array $mirroredLineIds = []): string
    {
        return self::compose(ServicingFingerprint::review($serviced, $live, $mirroredLineIds), $live);
    }

    public static function current(QuoteSnapshot $live): string
    {
        return self::compose(ServicingFingerprint::of($live), $live);
    }

    private static function compose(string $buyer, QuoteSnapshot $live): string
    {
        $discount = $live->totals->discount;
        $parts = [
            'buyer:' . $buyer,
            'net:' . self::money($live->totals->totalNet),
            'gross:' . ($live->totals->totalGross === null ? '' : self::money($live->totals->totalGross)),
            'discount:' . ($discount === null ? '' : $discount->type->value . ':' . self::money($discount->value)),
            'expires:' . ($live->lifecycle->expiresAt?->format(\DateTimeInterface::ATOM) ?? ''),
        ];
        $lines = [];

        foreach ($live->content->lines as $line) {
            $lines[$line->identity->lineItemId] = self::line($line);
        }

        ksort($lines);

        return 'v2:' . hash('sha256', implode('|', [...$parts, ...$lines]));
    }

    private static function line(QuoteLineSnapshot $line): string
    {
        return implode(':', [
            'line',
            $line->identity->lineItemId,
            $line->identity->label ?? '',
            $line->identity->productId ?? '',
            (string) $line->quantity,
            self::money($line->unitPriceNet),
            self::money($line->totalNet),
            self::money($line->totalInQuotePriceSpace ?? self::quotedTotal($line)),
            $line->updatedAt?->format('Y-m-d\TH:i:s.uP') ?? '',
        ]);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function quotedTotal(QuoteLineSnapshot $line): float
    {
        return $line->netRatio === 0.0 ? $line->totalNet : $line->totalNet / $line->netRatio;
    }
}
