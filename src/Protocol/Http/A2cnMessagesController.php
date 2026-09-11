<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActAppender;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActRefusal;
use MerchantQuoteAgentPlugin\Protocol\Ingress\InboundActRequest;
use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A2CN's own inbound message route: `POST {endpoint}/sessions/{id}/messages`,
 * where `{endpoint}` is what our discovery document advertises.
 *
 * The path is the protocol's, not ours. The reference implementation's client
 * builds every session URL as `f"{endpoint}/sessions/{session_id}/..."`, so a
 * conformant buyer agent needs no shop-specific code to negotiate with this
 * shop — which is the entire reason for not inventing a route here.
 *
 * Under `/a2cn` rather than at the domain root, and the discovery document
 * carries the prefix in `endpoint`. Claiming `/sessions` at the root of a
 * Shopware storefront would be a landgrab on the merchant's own URL space.
 *
 * Everything this method decides is a lookup or a translation; the rules live
 * in Ingress\, and the ordering of those rules is documented there. What is
 * decided HERE and nowhere else: the token's audience is the DID this SALES
 * CHANNEL signs under (the same identity the emitter uses, so the buyer's
 * `aud` matches the DID they discovered), and the whole append runs inside
 * the per-quote servicing lock.
 *
 * The quote's state and the chain it carries are read TOGETHER, inside the
 * lock, from one `QuoteTerminalStateReader::for()` call — not one before the
 * lock and another after. Reading state before the lock let two overlapping
 * requests each judge eligibility against a snapshot the other had already
 * invalidated: request A reads "live, no acceptance" and starts servicing,
 * request B acquires the lock and appends the buyer's acceptance, A then
 * acquires the lock, reads the now-closed chain, but still holds its stale
 * "live" state and appends to a session that already closed. Reading both
 * together, after the lock, means a request either sees the FULL post-close
 * picture or none of it. This also happens to remove one of the two full
 * `fetchSnapshot()` reads this route used to make per request — a real
 * saving, but the read ordering is what the restructuring is actually for.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final readonly class A2cnMessagesController
{
    private const CONTENT_TYPE = 'application/a2cn+json';

    /**
     * @mago-expect lint:excessive-parameter-list
     * The controller orchestrates the route across six collaborators.
     */
    public function __construct(
        private A2cnBearerJwt $tokens,
        private SessionQuoteLocator $locator,
        private QuoteTerminalStateReader $quotes,
        private A2cnIdentityResolver $identities,
        private QuoteServicingLock $locks,
        private InboundActAppender $appender,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    #[Route(
        path: '/a2cn/sessions/{sessionId}/messages',
        name: 'frontend.merchant_quote_agent.a2cn.messages.post',
        methods: ['POST'],
    )]
    public function messages(string $sessionId, Request $request): JsonResponse
    {
        $quoteId = $this->locator->quoteIdFor($sessionId);
        if ($quoteId === null) {
            return self::refuse(new InboundActRefusal(404, 'not_found'));
        }

        $lock = $this->locks->for($quoteId);
        if (!$lock->acquire()) {
            return self::refuse(new InboundActRefusal(409, 'session_busy'));
        }

        try {
            return $this->resolveAndAppend($quoteId, $sessionId, $request);
        } catch (QuoteStateUnavailable) {
            return self::refuse(new InboundActRefusal(502, 'quote_state_unavailable'));
        } finally {
            $lock->release();
        }
    }

    /**
     * Everything that needs the quote's state — read once, inside the lock,
     * per the class docblock. `identities->forSalesChannel()` and
     * `tokens->issuerOf()` used to run before the lock existed at all,
     * against a `$quote` read before the lock; they still run in the same
     * order, just against the read that now happens where it can't go
     * stale mid-request.
     *
     * @throws QuoteStateUnavailable
     * @throws \Doctrine\DBAL\Exception
     */
    private function resolveAndAppend(string $quoteId, string $sessionId, Request $request): JsonResponse
    {
        $now = new \DateTimeImmutable();
        $quote = $this->quotes->for($quoteId, $now);
        if ($quote === null) {
            return self::refuse(new InboundActRefusal(404, 'not_found'));
        }

        $identity = $this->identities->forSalesChannel($quote->salesChannelId);
        if ($identity === null) {
            // We cannot state who the token's audience should be, so we
            // cannot authenticate anyone. Same posture as the discovery
            // routes: say so, do not 500.
            return self::refuse(new InboundActRefusal(503, 'signing_key_missing'));
        }

        $issuer = $this->tokens->issuerOf((string) $request->headers->get('Authorization', ''), $identity->did, $now);
        if ($issuer === null) {
            return self::refuse(new InboundActRefusal(401, 'invalid_jwt'));
        }

        $act = InboundActPayload::from($request);
        if ($act === null) {
            return self::refuse(new InboundActRefusal(400, 'invalid_act'));
        }

        return $this->respondToAppend($act, $quoteId, $sessionId, $quote, $identity->did, $issuer);
    }

    /**
     * @mago-expect lint:excessive-parameter-list
     * All six fields are required to construct InboundActRequest.
     */
    private function respondToAppend(
        Act $act,
        string $quoteId,
        string $sessionId,
        QuoteTerminalState $quote,
        string $sellerDid,
        string $issuerDid,
    ): JsonResponse {
        // The chain comes from the SAME read as $quote's state — see the
        // class docblock — never a second fetchSnapshot().
        $result = $this->appender->append(new InboundActRequest(
            act: $act,
            chain: ActChain::read($quote->customFields),
            quoteId: $quoteId,
            sessionId: $sessionId,
            quote: $quote,
            sellerDid: $sellerDid,
            issuerDid: $issuerDid,
        ));

        if ($result instanceof InboundActRefusal) {
            return self::refuse($result);
        }

        // Object identity distinguishes a fresh append from an idempotent replay:
        // the appender returns the caller's own Act on fresh write, and the
        // chain's parsed Act on replay.
        $fresh = $result === $act;

        return self::respond(
            [
                'session_id' => $sessionId,
                'accepted' => [
                    'message_id' => $result->messageId(),
                    'sequence_number' => $result->sequenceNumber(),
                ],
            ],
            $fresh ? 201 : 200,
        );
    }

    private static function refuse(InboundActRefusal $refusal): JsonResponse
    {
        return self::respond($refusal->toArray(), $refusal->status);
    }

    /** @param array<string, mixed> $body */
    private static function respond(array $body, int $status): JsonResponse
    {
        $response = JsonEnvelope::noStore($body, $status);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }
}
