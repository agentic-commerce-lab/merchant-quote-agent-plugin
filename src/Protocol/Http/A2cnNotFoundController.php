<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Everything under the advertised A2CN endpoint that this installation does
 * not implement, answered as A2CN rather than as a web page.
 *
 * Without this, an unrouted path below `/a2cn` falls through to the
 * storefront's error page: 132 KiB of HTML under **HTTP 200**, which a
 * protocol client cannot tell apart from success without sniffing the content
 * type. The reference client's session-state call
 * (`GET {endpoint}/sessions/{id}`, a route we deliberately do not serve, since
 * sessions here are derived from a quote rather than opened) hit exactly that
 * during interoperability testing.
 *
 * Registered unconditionally, next to the discovery document and for the same
 * reason: discovery advertises `{base}/a2cn` as the endpoint whether or not
 * the commercial quote backend is present, so every path beneath it owes a
 * protocol answer either way.
 *
 * `priority: -100` is what keeps this from swallowing the real routes. Symfony
 * orders the collection by priority, and the matcher takes the first hit, so
 * every A2CN route registered at the default priority is tried before this
 * one. A catch-all without it would shadow the whole module.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class A2cnNotFoundController
{
    #[Route(
        path: '/a2cn/{trail}',
        name: 'frontend.merchant_quote_agent.a2cn.not_found',
        requirements: ['trail' => '.*'],
        defaults: ['trail' => ''],
        methods: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        priority: -100,
    )]
    public function notFound(): JsonResponse
    {
        $response = JsonEnvelope::noStore(['status' => 'not_found'], 404);
        $response->headers->set('Content-Type', 'application/a2cn+json');

        return $response;
    }
}
