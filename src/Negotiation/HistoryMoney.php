<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/** Original history amounts always travel with their own currency, never an assumed shop currency. */
final readonly class HistoryMoney
{
    public static function of(float $amount, ?string $currencyIso): string
    {
        return sprintf('%.2f %s', $amount, $currencyIso ?? 'currency unknown');
    }
}
