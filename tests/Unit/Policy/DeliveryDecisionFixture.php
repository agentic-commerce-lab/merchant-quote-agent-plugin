<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryDecision;

final class DeliveryDecisionFixture
{
    public static function fromArray(?array $data): ?DeliveryDecision
    {
        return $data === null
            ? null
            : new DeliveryDecision(
                band: Band::from($data['band']),
                freeShippingGranted: $data['freeShippingGranted'] ?? null,
                expeditedGranted: $data['expeditedGranted'] ?? null,
                committedLeadTimeDays: $data['committedLeadTimeDays'] ?? null,
            );
    }
}
