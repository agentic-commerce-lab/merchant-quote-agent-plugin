<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/** Carries a verified UCP customer from the controller to the response audit. */
final class AuthenticatedCustomerAttribute
{
    public const KEY = 'merchant_quote_agent.authenticated_customer_id';

    private function __construct() {}

    public static function remember(Request $request, string $customerId): void
    {
        $request->attributes->set(self::KEY, $customerId);
    }

    public static function of(Request $request): ?string
    {
        $customerId = $request->attributes->get(self::KEY);

        return \is_string($customerId) && Uuid::isValid($customerId) ? $customerId : null;
    }
}
