<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use Override;
use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

/**
 * Admits the requesting agent's own profile host on sales channels whose
 * allow-any-agent flag is on.
 *
 * The SDK takes the profile-host and agent-domain gates from whatever this
 * interface returns (see DefaultHttpRequestContextFactory), and the Agentic
 * Commerce plugin aliases the interface to its own resolver, so decorating it
 * widens both gates without touching that plugin.
 *
 * "Both gates" means the two in DefaultHttpRequestContextFactory, but
 * allowedAgentDomains has a further consumer worth knowing about:
 * EmbeddedController feeds it to OriginMatcher, so on a flagged channel an
 * announced host also becomes an allowed CORS origin and
 * Content-Security-Policy frame-ancestors value for /ucp/embedded/*. Not
 * reachable from a browser -- the preflight allows only Content-Type and
 * Accept, so no cross-origin fetch may carry a UCP-Agent header, and a framing
 * navigation carries none either.
 *
 * What it does not touch is the
 * installation-wide list the SDK's UrlSafetyValidator enforces — that is
 * {@see AgentProfileHostValidatorFactory}.
 *
 * This widens an identity allowlist and nothing else: the agent still has to
 * publish a profile the shop can fetch, and the signature check on the request
 * still runs against the keys in it. Anything unattributable — no header, an
 * unparseable one, a host no sales channel serves — widens nothing.
 */
final readonly class AgentAdmittingRuntimeConfigurationResolver implements RuntimeConfigurationResolverInterface
{
    public function __construct(
        private RuntimeConfigurationResolverInterface $inner,
        private AgentAccessFlags $flags,
        private CustomerContextResolverInterface $contextResolver,
    ) {}

    #[Override]
    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        $configuration = $this->inner->resolve($request);
        $agentHost = UcpAgentHeader::profileHostFromHeaders($request->headers);

        if ($agentHost === null) {
            return $configuration;
        }

        $host = parse_url($request->absoluteUri, \PHP_URL_HOST);
        $salesChannelId = $this->contextResolver->resolveByHost(\is_string($host) ? $host : null)?->salesChannelId;

        if (!$this->flags->allowAnyAgent($salesChannelId)) {
            return $configuration;
        }

        return new RuntimeConfiguration(
            $configuration->version,
            $configuration->baseUri,
            $configuration->signaturePolicy,
            $configuration->idempotencyRequired,
            self::withHost($configuration->allowedProfileHosts, $agentHost),
            self::withHost($configuration->allowedAgentDomains, $agentHost),
            $configuration->supportedVersions,
            $configuration->transports,
            $configuration->enabledCapabilities,
            $configuration->tenantIdentifier,
            $configuration->transportEndpoints,
            $configuration->profileFetchingDevelopmentMode,
        );
    }

    /**
     * @param list<string> $hosts
     * @return list<string>
     */
    private static function withHost(array $hosts, string $host): array
    {
        return \in_array($host, $hosts, strict: true) ? $hosts : [...$hosts, $host];
    }
}
