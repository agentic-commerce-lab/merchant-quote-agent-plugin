<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

final class QuoteServicingStates
{
    private const SERVICEABLE = ['open', 'in_review', 'change_requested'];

    private function __construct() {}

    public static function isServiceable(string $state): bool
    {
        return \in_array($state, self::SERVICEABLE, strict: true);
    }
}
