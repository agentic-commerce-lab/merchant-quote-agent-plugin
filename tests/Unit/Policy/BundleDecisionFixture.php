<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\BundleDecision;

final class BundleDecisionFixture
{
    public static function fromArray(?array $data): ?BundleDecision
    {
        return $data === null
            ? null
            : new BundleDecision(
                band: Band::from($data['band']),
                grantedDiscountPercent: $data['grantedDiscountPercent'] ?? null,
            );
    }
}
