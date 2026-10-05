<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote\Controller;

use MerchantQuoteAgentPlugin\Identity\AgentCustomerAuthenticator;
use MerchantQuoteAgentPlugin\Identity\AgentCustomerCredential;
use MerchantQuoteAgentPlugin\Identity\AuthenticatedCustomerAttribute;
use MerchantQuoteAgentPlugin\Identity\UcpRequestContext;
use MerchantQuoteAgentPlugin\Protocol\Ingress\A2cnSessionStamp;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteCapability;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteOAuthScopeProvider;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteRequestValidator;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

/**
 * Transport for the vendor capability `com.shopware.quote`.
 *
 * Everything reusable is reused from the SDK HTTP layer: the request context
 * (and with it the UCP-Agent requirement and signature policy) comes off the
 * `ucp_request_context` request attribute the SDK's RequestContextListener
 * already builds for everything under `/ucp/`, responses from
 * UcpResponseFactory, and errors from the SDK's ExceptionListener - both
 * listeners already match `/ucp/*`.
 *
 * Only the routing is plugin-owned, and only because it has to be: the SDK's
 * ShoppingOperationExecutor dispatches a fixed set of `dev.ucp.*` operations and
 * its negotiator derives operations from the seven known SDK capability
 * interfaces, so a vendor capability contributes no operations and cannot be
 * routed there. Fold these routes into the shared operation layer as soon as the
 * SDK gains an extensible operation registry.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class UcpQuoteController
{
    private const DEFAULT_LIST_LIMIT = 25;

    public function __construct(
        private readonly QuoteCapability $quoteCapability,
        private readonly AgentCustomerAuthenticator $authenticator,
        private readonly UcpResponseFactory $responseFactory,
        private readonly QuoteRequestValidator $requestValidator,
        private readonly A2cnSessionStamp $sessions,
    ) {}

    #[Route(path: '/ucp/quotes', name: 'frontend.merchant_quote_agent.quote.request', methods: ['POST'])]
    public function requestQuote(Request $request): JsonResponse
    {
        $context = UcpRequestContext::of($request);
        $customerContext = $this->customerContext($request, $context);
        $payload = $this->payload($request);

        // Only requestQuote stamps the A2CN session id. getQuote and
        // counterQuote are deliberately untouched: a buyer that lost the id
        // can recompute it via SessionId::forQuote(), and re-reading
        // customFields on every quote read just to echo a value the buyer
        // can already derive is not worth it.
        $snapshot = $this->sessions->stamp($this->quoteCapability->requestQuote(
            $customerContext,
            $this->requestValidator->lineItems($payload, true),
            $this->requestValidator->comment($payload),
        ));

        return $this->responseFactory->success(
            $snapshot->toArray(),
            Response::HTTP_CREATED,
            [],
            $context,
            'quote.request',
        );
    }

    #[Route(path: '/ucp/quotes', name: 'frontend.merchant_quote_agent.quote.list', methods: ['GET'])]
    public function listQuotes(Request $request): JsonResponse
    {
        $context = UcpRequestContext::of($request);

        $list = $this->quoteCapability->listQuotes(
            $this->customerContext($request, $context),
            $request->query->getInt('limit', self::DEFAULT_LIST_LIMIT),
            $request->query->getInt('page', 1),
        );

        return $this->responseFactory->success($list->toArray(), Response::HTTP_OK, [], $context, 'quote.list');
    }

    #[Route(path: '/ucp/quotes/{id}', name: 'frontend.merchant_quote_agent.quote.get', methods: ['GET'])]
    public function getQuote(string $id, Request $request): JsonResponse
    {
        $context = UcpRequestContext::of($request);
        $snapshot = $this->quoteCapability->getQuote($this->customerContext($request, $context), $id);

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.get');
    }

    #[Route(path: '/ucp/quotes/{id}/counter', name: 'frontend.merchant_quote_agent.quote.counter', methods: ['POST'])]
    public function counterQuote(string $id, Request $request): JsonResponse
    {
        $context = UcpRequestContext::of($request);
        $customerContext = $this->customerContext($request, $context);
        $payload = $this->payload($request);

        $snapshot = $this->quoteCapability->counterQuote(
            $customerContext,
            $id,
            $this->requestValidator->lineItems($payload, false),
            $this->requestValidator->comment($payload),
        );

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.counter');
    }

    #[Route(path: '/ucp/quotes/{id}/accept', name: 'frontend.merchant_quote_agent.quote.accept', methods: ['POST'])]
    public function acceptQuote(string $id, Request $request): JsonResponse
    {
        $context = UcpRequestContext::of($request);
        // Accepting places the order, so the token must also be allowed to manage orders.
        $customerContext = $this->customerContext(
            $request,
            $context,
            [
                QuoteOAuthScopeProvider::MANAGE_QUOTES,
                QuoteOAuthScopeProvider::MANAGE_ORDERS,
            ],
        );
        $snapshot = $this->quoteCapability->acceptQuote($customerContext, $id);

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.accept');
    }

    #[Route(path: '/ucp/quotes/{id}/decline', name: 'frontend.merchant_quote_agent.quote.decline', methods: ['POST'])]
    public function declineQuote(string $id, Request $request): JsonResponse
    {
        $context = UcpRequestContext::of($request);
        $customerContext = $this->customerContext($request, $context);
        $payload = $this->payload($request);

        $snapshot = $this->quoteCapability->declineQuote(
            $customerContext,
            $id,
            $this->requestValidator->comment($payload),
        );

        return $this->responseFactory->success($snapshot->toArray(), Response::HTTP_OK, [], $context, 'quote.decline');
    }

    /**
     * @param list<string> $requiredScopes
     */
    private function customerContext(
        Request $request,
        RequestContext $context,
        array $requiredScopes = [QuoteOAuthScopeProvider::MANAGE_QUOTES],
    ): SalesChannelContext {
        $credential = AgentCustomerCredential::fromAuthorizationHeader((string) $request->headers->get(
            'Authorization',
            '',
        ));

        $salesChannelContext = $this->authenticator->authenticate($credential, $context, $requiredScopes);
        $customerId = $salesChannelContext->getCustomer()?->getId();
        if ($customerId !== null) {
            AuthenticatedCustomerAttribute::remember($request, $customerId);
        }

        return $salesChannelContext;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $content = $request->getContent();

        if ('' === $content) {
            return [];
        }

        try {
            $payload = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException('Request body must be valid JSON.', ['$ must be a JSON object']);
        }

        if (!\is_array($payload)) {
            throw new ValidationException('Request body must be a JSON object.', ['$ must be a JSON object']);
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
