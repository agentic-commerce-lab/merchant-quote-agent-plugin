<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Controller;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\SalesChannelDomainUrlReader;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Where an agent registers an authorization request before sending a human to
 * the shop.
 *
 * A POST with a JSON body and deliberately no query string: the SDK verifies
 * `@target-uri` against Symfony's Request::getUri(), which re-sorts and
 * re-encodes the query, so a signed GET with parameters fails verification
 * unless the client reproduces that normalisation exactly. A body avoids the
 * trap for every client.
 *
 * Lives under `/ucp/` because that is where the SDK's RequestContextListener
 * builds a context — which is what makes the agent's identity known here and
 * unknown on the browser hop that follows.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class AgentAuthorizationRequestController
{
    public function __construct(
        private readonly AgentAuthorizationRegistrar $registrar,
        private readonly CustomerContextResolverInterface $contextResolver,
        private readonly SalesChannelDomainUrlReader $domains,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Route(
        path: '/ucp/quote-agent/authorization-requests',
        name: 'frontend.merchant_quote_agent.authorization_request',
        methods: ['POST'],
    )]
    public function register(Request $request): JsonResponse
    {
        $context = $this->requestContext($request);
        $resolution = $this->contextResolver->resolveSalesChannel($context);

        // Resolved and checked BEFORE register() writes anything: a
        // misconfigured channel must fail loudly without persisting a row a
        // human can never be sent to answer.
        $base = $this->domains->urlFor($resolution->domainId);

        if ($base === null) {
            throw new ConfigurationException(
                'The sales channel has no domain URL, so no consent page can be addressed.',
            );
        }

        $handle = $this->registrar->register($this->payload($request), $context, $resolution->salesChannelId);

        return new JsonResponse([
            'request_uri' => $handle,
            'expires_in' => AgentAuthorizationRegistrar::TTL_SECONDS,
            'authorization_url' => $base . '/quote-agent/authorize?request_uri=' . urlencode($handle),
        ], Response::HTTP_CREATED);
    }

    private function requestContext(Request $request): RequestContext
    {
        $context = $request->attributes->get('ucp_request_context');

        if (!$context instanceof RequestContext) {
            throw new ConfigurationException(
                'No UCP request context on the request. The SDK listener only builds one below /ucp/, '
                . 'so this route is registered outside the prefix it depends on.',
            );
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function payload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('Request body must be valid JSON.', ['$ must be a JSON object']);
        }

        if (!\is_array($payload) || array_is_list($payload)) {
            throw new ValidationException('Request body must be a JSON object.', ['$ must be a JSON object']);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
