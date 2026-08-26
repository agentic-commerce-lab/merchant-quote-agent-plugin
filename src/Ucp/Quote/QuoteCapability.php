<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use Override;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/**
 * Registers `com.shopware.quote` with the UCP SDK.
 *
 * Implementing CapabilityInterface is all the registration takes: the SDK
 * autoconfigures the `ucp_sdk.capability` tag onto every implementation and
 * collects them into its CapabilityRegistry. No decoration of the Agentic
 * Commerce plugin, and no changes to it.
 *
 * The buyer-facing operations (request, get, list, counter, accept, decline)
 * arrive with the gateway in issue #9. Until then this only declares that the
 * capability exists.
 */
final class QuoteCapability implements CapabilityInterface
{
    #[Override]
    public function describe(): CapabilityDescriptor
    {
        return QuoteCapabilityDescriptor::paths();
    }
}
