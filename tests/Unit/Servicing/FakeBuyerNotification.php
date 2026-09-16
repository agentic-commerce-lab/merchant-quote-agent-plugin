<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\BuyerNotificationPreference;

/** The merchant's buyer-notice toggle, flippable mid-test. */
final class FakeBuyerNotification implements BuyerNotificationPreference
{
    public function __construct(
        public bool $notify = true,
    ) {}

    #[\Override]
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool
    {
        return $this->notify;
    }
}
