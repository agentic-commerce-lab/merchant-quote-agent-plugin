<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the two documents the `com.shopware.quote` descriptor advertises: the
 * OpenAPI contract and the prose specification.
 *
 * {@see QuoteCapabilityDescriptor} publishes both URLs on the shop's own domain,
 * so the plugin that publishes the descriptor has to serve them too — no
 * central infrastructure, and nothing that resolves only while a particular
 * Agentic Commerce build is installed.
 *
 * Both paths sit outside `/ucp/` on purpose (see the descriptor): an agent reads
 * them straight from the discovery document, before it has negotiated anything,
 * so the SDK's `UCP-Agent` requirement must not apply.
 *
 * The route scope is the storefront's, written as a literal because
 * `shopware/storefront` is not a dependency of this plugin — only
 * `shopware/core` is, and the constant lives in the storefront bundle.
 *
 * @internal
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class QuoteContractController
{
    private const CACHE_CONTROL = 'public, max-age=300';

    public function __construct(
        private readonly string $schemaPath,
        private readonly string $specPath,
    ) {}

    #[Route(
        path: QuoteCapabilityDescriptor::SCHEMA_PATH,
        name: 'frontend.merchant_quote_agent.quote.schema',
        methods: ['GET'],
    )]
    public function schema(): Response
    {
        return $this->serve($this->schemaPath, 'application/json');
    }

    #[Route(
        path: QuoteCapabilityDescriptor::SPEC_PATH,
        name: 'frontend.merchant_quote_agent.quote.spec',
        methods: ['GET'],
    )]
    public function spec(): Response
    {
        return $this->serve($this->specPath, 'text/html; charset=UTF-8');
    }

    private function serve(string $path, string $contentType): Response
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (!\is_string($contents)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
    }
}
