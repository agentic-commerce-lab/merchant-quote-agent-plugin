<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/**
 * A2CN §14.1: the act's own envelope must agree with the request that carried
 * it.
 *
 * Both bindings close the same hole from opposite sides. Without the session
 * binding, an act signed for one session could be replayed into another; the
 * signature would still verify, because `session_id` is inside the signed
 * view and the buyer really did sign it — for a different negotiation.
 * Without the sender binding, anyone holding a valid token of their own could
 * post someone else's act and have it recorded as delivered by them.
 */
final readonly class InboundActEnvelope
{
    private function __construct() {}

    public static function refusal(Act $act, string $sessionId, string $issuerDid): ?InboundActRefusal
    {
        if ($act->sessionId() !== $sessionId) {
            return new InboundActRefusal(400, 'session_id_mismatch');
        }

        return $act->senderDid() === $issuerDid ? null : new InboundActRefusal(401, 'sender_did_mismatch');
    }
}
