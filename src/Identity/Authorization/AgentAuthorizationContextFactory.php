<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Shopware\Core\PlatformRequest;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * Builds the RequestContext that consent hands to Agentic Commerce.
 *
 * `signatureVerified: true` is asserted here, and it is truthful only because
 * AgentAuthorizationRegistrar refused to store the record unless the SDK had
 * verified the agent's signature. The two classes are one mechanism: read them
 * together before changing either.
 *
 * The context carries the logged-in customer's context token, which is what
 * lets AC's adapter resolve a customer and mint a code against it.
 *
 * It also carries a real `RuntimeConfiguration`, and that is not optional:
 * `IdentityLinkingCapability::authorize()` opens with
 * `CapabilityGuard::assertEnabled()`, which reads that field and treats `null`
 * as "the identity-linking capability is disabled for this sales channel". A
 * context built without one is refused on every single grant. The caller
 * obtains it from {@see RequestRuntimeConfigurationReader}; this class takes
 * it as a value so it stays free of the Symfony request, which is what lets it
 * move upstream unchanged.
 *
 * `PendingAuthorization::$agentProfile` must have been through a JSON
 * round-trip (as the store does on write/read): PlatformProfile::toArray()
 * renders empty `services`/`capabilities`/`payment_handlers` maps as
 * `stdClass`, but PlatformProfile::fromArray() requires arrays and throws
 * ValidationException on the stdClass form. Passing a freshly-`toArray()`d
 * profile straight to `forConsent()` without that round-trip will throw.
 */
final readonly class AgentAuthorizationContextFactory
{
    public function forConsent(
        PendingAuthorization $pending,
        string $host,
        #[\SensitiveParameter]
        string $customerContextToken,
        RuntimeConfiguration $runtimeConfiguration,
    ): RequestContext {
        return new RequestContext(
            $host,
            [strtolower(PlatformRequest::HEADER_CONTEXT_TOKEN) => $customerContextToken],
            $pending->clientId,
            PlatformProfile::fromArray($pending->agentProfile),
            [],
            true,
            runtimeConfiguration: $runtimeConfiguration,
        );
    }
}
