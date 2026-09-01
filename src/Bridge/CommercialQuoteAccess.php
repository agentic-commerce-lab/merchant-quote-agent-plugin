<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;

/**
 * "May this buyer request be served at all, and on whose behalf" — the
 * preconditions the fork's customerContext() checked before any of the six
 * operations touched a Store API route.
 *
 * Separate from the gateway because it is a different question from "which
 * commercial route implements this operation": these checks are the same
 * for every operation, the routes are not. `service()` is static because it
 * has nothing of its own to hold — CommercialQuoteLinePricing's line-item
 * route needs the identical null guard and takes it from here too.
 */
final readonly class CommercialQuoteAccess
{
    /** Feature key in `customer_specific_features` (a map, not a list). */
    private const CUSTOMER_FEATURE = 'QUOTE_MANAGEMENT';

    public function __construct(
        private ?object $customerSpecificFeatureService = null,
    ) {}

    /**
     * Whether the customer-specific-feature service actually resolved. It is
     * `nullOnInvalid()` like the gateway's own routes, and every one of the
     * four mutating operations needs it via assertCustomerHasQuoteFeature() —
     * a shop missing only this service must not advertise the capability as
     * available and then fail every mutation with a 501.
     */
    public function isAvailable(): bool
    {
        return null !== $this->customerSpecificFeatureService;
    }

    /**
     * ADR 0001's stage two: the licence toggle every commercial quote SERVICE
     * checks on entry. A shop with SwagCommercial installed but unlicensed
     * must not serve quote requests, counter-offers or orders just because
     * the classes exist.
     */
    public function assertServable(): void
    {
        if (!CommercialAvailability::isLicensed()) {
            throw new UnsupportedCapabilityException('Quote management is not licensed for this shop.');
        }
    }

    /**
     * The trust boundary every operation shares: a context without a customer
     * cannot act as one. `SalesChannelContext::getCustomer()` is nullable
     * regardless of what the identity layer guarantees today.
     */
    public function requireCustomerId(SalesChannelContext $context): string
    {
        $customerId = $context->getCustomer()?->getId();

        if (null === $customerId) {
            throw new ValidationException('Quote operations require a linked customer context.');
        }

        return $customerId;
    }

    /**
     * Only the four mutating operations call this: reading an existing quote
     * doesn't need the live feature flag, only starting or changing one does.
     */
    public function assertCustomerHasQuoteFeature(string $customerId): void
    {
        $isAllowed = self::service($this->customerSpecificFeatureService, 'customer-specific-feature')
            ->isAllowed($customerId, self::CUSTOMER_FEATURE);

        if (true !== $isAllowed) {
            throw new ValidationException('Quote management is not enabled for this customer: customer_specific_features must contain {"QUOTE_MANAGEMENT": true}.', [
                'customer_specific_features must contain {"QUOTE_MANAGEMENT": true}',
            ]);
        }
    }

    /**
     * Every commercial service this bridge injects is individually nullable
     * so the plugin degrades service by service — this is where a missing
     * one actually surfaces, as a legible exception instead of a fatal error
     * on a null method call.
     */
    public static function service(?object $service, string $name): object
    {
        if (null === $service) {
            throw new UnsupportedCapabilityException(\sprintf('The commercial %s service is unavailable.', $name));
        }

        return $service;
    }
}
