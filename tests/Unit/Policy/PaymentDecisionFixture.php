<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;

final class PaymentDecisionFixture
{
    public static function fromArray(?array $data): ?PaymentDecision
    {
        if ($data === null) {
            return null;
        }

        $term = $data['grantedTerm'] ?? null;

        return new PaymentDecision(
            band: Band::from($data['band']),
            grantedTerm: $term === null ? null : PaymentTerm::from($term),
            grantedNetDays: $data['grantedNetDays'] ?? null,
            grantedDepositPercent: $data['grantedDepositPercent'] ?? null,
        );
    }
}
