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

        $now = new \DateTimeImmutable();

        try {
            $quote = $this->quotes->for($quoteId, $now);
        } catch (QuoteStateUnavailable) {
            return self::refuse(new InboundActRefusal(502, 'quote_state_unavailable'));
        }

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

        return $this->appendUnderLock($act, $quoteId, $sessionId, $quote, $identity->did, $issuer);
    }

    /**
     * @mago-expect lint:excessive-parameter-list
     * All six fields are required to construct InboundActRequest inside the servicing lock.
     */
    private function appendUnderLock(
        Act $act,
        string $quoteId,
        string $sessionId,
        QuoteTerminalState $quote,
        string $sellerDid,
        string $issuerDid,
    ): JsonResponse {
        $lock = $this->locks->for($quoteId);
        if (!$lock->acquire()) {
            return self::refuse(new InboundActRefusal(409, 'session_busy'));
        }

        try {
            $chainSource = $this->quotes->customFieldsFor($quoteId);
            $chain = ActChain::read($chainSource);
            $result = $this->appender->append(new InboundActRequest(
                act: $act,
                chain: $chain,
                quoteId: $quoteId,
                sessionId: $sessionId,
                quote: $quote,
                sellerDid: $sellerDid,
                issuerDid: $issuerDid,
            ));
        } catch (QuoteStateUnavailable) {
            return self::refuse(new InboundActRefusal(502, 'quote_state_unavailable'));
        } finally {
            $lock->release();
        }

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
