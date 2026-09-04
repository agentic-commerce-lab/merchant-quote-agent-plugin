<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Every act-chain and records response: JSON, `Cache-Control: no-store`.
 *
 * Records are derived per request, never cached — a stored record can go
 * stale against its own chain, and deriving is cheap. Shared by the
 * controller and RecordResponder rather than duplicated in each: one place
 * that stamps the header is one place to get it right.
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
}
