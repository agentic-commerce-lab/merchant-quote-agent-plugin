<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * The object an A2CN `acceptance` signs — spec section 7.4 — which is NOT the
 * nine-field protocol act object every other act signs.
 *
 * An acceptance in A2CN is a different shape from the offers around it. It
 * carries no `protocol_act_hash` and no `protocol_act_signature`; instead it
 * names the offer it accepts and signs these five fields into
 * `acceptance_signature`. That asymmetry is the counterparty's design, not
 * ours, and we have asked the working group to reconcile it — but until they
 * do, refusing their acceptance would mean the reference client cannot close
 * a deal with this shop, which is a worse outcome than reading both shapes.
 *
 * The field set below is NORMATIVE, exactly as SignedView's is: it must match
 * the reference implementation's `acceptance_payload()` member for member, or
 * a signature they made will not verify here. Order is irrelevant to the bytes
 * (JCS sorts keys) and is kept in spec order for reading against the spec.
 *
 * Values are taken verbatim from the wire, never defaulted into shape: an
 * acceptance that omits `round_number` produces `null` here and simply fails
 * to verify, which is the correct answer for a message the counterparty did
 * not sign the way they said they would.
 */
final class AcceptanceView
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(Act $act): array
    {
        return [
            'session_id' => $act->sessionId(),
            'round_number' => $act->roundNumber(),
            'sequence_number' => $act->sequenceNumber(),
            'accepted_offer_id' => $act->acceptedOfferId(),
            'accepted_protocol_act_hash' => $act->acceptedProtocolActHash(),
        ];
    }
}
