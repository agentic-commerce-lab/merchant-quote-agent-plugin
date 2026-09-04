<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The act chain and the end-of-session records, over HTTP.
 *
 * Records are derived per request, never cached: a stored record can go stale
 * against its own chain, and deriving is cheap.
 *
 * Authorization is the session id itself — a UUIDv5 over a Shopware quote id,
 * so unguessable, and the buyer already holds it. A fully public endpoint would
 * let anyone enumerate a merchant's deal terms; "verification is open to
 * anyone" is about verifying a record you were given.
 * ponytail: capability URL, no revocation. Upgrade to a signed fetch on the UCP
 * key if a leaked link ever matters.
 *
 * Record selection and response shaping (which record a quote's state calls
 * for, the parties, the acceptance-without-offer check) live in
 * RecordResponder — a real seam from this class's job, which is the two
 * routes and the store/gateway orchestration in front of them; splitting on
 * it is what keeps this class under the per-class cyclomatic-complexity gate.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final readonly class A2cnRecordsController
{
    public function __construct(
        private ActStoreInterface $store,
        private QuoteTerminalStateReader $quotes,
        private RecordResponder $responder,
    ) {}

    #[Route(path: '/a2cn/sessions/{sessionId}/acts', name: 'frontend.merchant_quote_agent.a2cn.acts', methods: ['GET'])]
    public function acts(string $sessionId): JsonResponse
    {
        $acts = $this->store->listBySession($sessionId);
        if ($acts === []) {
            return JsonEnvelope::noStore(['status' => 'not_found'], 404);
        }

        return JsonEnvelope::noStore([
            'session_id' => $sessionId,
            'acts' => array_map(static fn(Act $act): array => $act->raw(), $acts),
        ]);
    }

    #[Route(path: '/a2cn/records/{sessionId}', name: 'frontend.merchant_quote_agent.a2cn.record', methods: ['GET'])]
    public function record(string $sessionId): JsonResponse
    {
        $acts = $this->store->listBySession($sessionId);
        $quoteId = $this->store->quoteIdForSession($sessionId);
        if ($acts === [] || $quoteId === null) {
            return JsonEnvelope::noStore(['status' => 'not_found'], 404);
        }

        try {
            $quote = $this->quotes->for($quoteId, new \DateTimeImmutable());
        } catch (QuoteStateUnavailable) {
            // A records request must not depend on Shopware being reachable
            // through a generic 500.
            return JsonEnvelope::noStore(['status' => 'quote_state_unavailable', 'session_id' => $sessionId], 502);
        }

        if ($quote === null) {
            return JsonEnvelope::noStore(['status' => 'not_found'], 404);
        }

        return $this->responder->respond($sessionId, $acts, $quote);
    }
}
