<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Ucp\Sdk\Internal\Service\UrlSafetyValidator;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/**
 * Builds the SDK's profile-fetch safety validator with the requesting agent's
 * host admitted, for sales channels configured with `allowAnyAgent`.
 *
 * Two independent allowlists gate an agent's profile URI: the sales channel's
 * (resolved per request, so `allowAnyAgent` can widen it directly) and the
 * installation-wide `ucp_sdk.allowed_profile_hosts`, which is a container value
 * fixed at compile time. Without this factory the second one would still reject
 * an unlisted agent, so the toggle would clear one gate and stop at the next --
 * exactly the confusing half-state it exists to remove.
 *
 * This replaces the bundle's own definition of the validator rather than
 * decorating the profile fetcher, because it keeps every other safety check
 * running unchanged: the validator still refuses plain http, non-443/8443 ports,
 * cloud metadata hosts, and hosts resolving into private or reserved ranges. The
 * only thing that widens is the *identity* allowlist. The trade-off is a
 * dependency on an `@internal` SDK class and on its service id, so
 * AgentAccessWiringTest pins both: that the container resolves a validator
 * built by this factory, and that a host nobody allowlisted is still refused.
 *
 * The SDK's own `UrlSafetyValidator::class` definition (services.php) is
 * non-shared so THIS factory rebuilds it per request. `HttpAgentProfileFetcher`,
 * the consumer this exists for, is itself a shared SDK service and holds
 * whichever validator it was built with for the life of the container --
 * under a long-running worker (FrankenPHP, RoadRunner) that is whichever
 * request was in scope the first time that fetcher was resolved. Non-shared
 * is still correct here (a fresh `->create()` call, e.g. from a test fetching
 * the id directly, always reflects the current request), and redefining the
 * fetcher too to chase full per-request accuracy is more coupling to the SDK
 * than a dev-oriented toggle is worth.
 */
/**
 * Second consumer, deliberately accepted: the SDK injects this same service id
 * into DefaultOrderWebhookDispatcher, which validates the OUTBOUND order
 * webhook target with it. So while a flagged agent's request is in scope, the
 * announced host also clears this installation-wide list for that dispatch.
 * Not an escalation an agent can drive: the target is the merchant-set
 * webhookUrlOverride, never agent-supplied, and Agentic Commerce gates it
 * independently through CheckoutWebhookUrlGuard against the persisted
 * per-channel allowlist. Every other check in the validator still runs.
 */
final class AgentProfileHostValidatorFactory
{
    public function __construct(
        private readonly UcpSdkConfiguration $sdkConfiguration,
        private readonly AgentAccessFlags $flags,
        private readonly CustomerContextResolverInterface $contextResolver,
        private readonly RequestStack $requestStack,
    ) {}

    public function create(): UrlSafetyValidator
    {
        return new UrlSafetyValidator(
            $this->allowedHosts(),
            null,
            $this->sdkConfiguration->profileFetchingDevelopmentMode,
        );
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $configured = $this->sdkConfiguration->allowedProfileHosts;

        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return $configured;
        }

        $agentHost = UcpAgentHeader::profileHost($request->headers->get(UcpAgentHeader::NAME));
        if ($agentHost === null) {
            return $configured;
        }

        $salesChannelId = $this->contextResolver->resolveByHost($request->getHost())?->salesChannelId;
        if (!$this->flags->allowAnyAgent($salesChannelId)) {
            return $configured;
        }

        return array_values(array_unique([...$configured, $agentHost]));
    }
}
