<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\NonPriceDecision;

/** Hydrates the negotiation-verify.json fixture's `decision` shape. */
final class NonPriceDecisionFixture
{
    public static function fromArray(array $data): NonPriceDecision
    {
        return new NonPriceDecision(
            band: Band::from($data['overall'] ?? 'grant'),
            delivery: DeliveryDecisionFixture::fromArray($data['delivery'] ?? null),
            payment: PaymentDecisionFixture::fromArray($data['payment'] ?? null),
            bundle: BundleDecisionFixture::fromArray($data['bundle'] ?? null),
        );
    }
}
