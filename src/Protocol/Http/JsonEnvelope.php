<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Every act-chain, records, and discovery response: JSON, with the
 * `Cache-Control` its content calls for.
 *
 * Records are derived per request, never cached — a stored record can go
 * stale against its own chain, and deriving is cheap, hence `noStore()`. The
 * mandate and the two discovery documents change only when the merchant
 * reconfigures, hence `cached()`. Shared by every consumer rather than
 * duplicated in each: one place that stamps the header is one place to get
 * it right.
 */
final class JsonEnvelope
{
    private function __construct() {}

    /** @param array<string, mixed> $body */
    public static function noStore(array $body, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @param array<string, mixed> $body */
    public static function cached(array $body, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'public, max-age=300');

        return $response;
    }
}
