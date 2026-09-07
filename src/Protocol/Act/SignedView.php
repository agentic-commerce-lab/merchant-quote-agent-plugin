<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * The protocol act object: the never-transmitted construct that is actually
 * signed.
 *
 * Keep it separate from the wire act, or the hash silently covers envelope
 * fields the counterparty does not sign. The field set below is NORMATIVE —
 * adding, removing or reordering a field changes every hash this plugin
 * produces and breaks agreement with every counterparty. `protocol_version` is
 * signed but never appears on the wire act.
 *
 * Field ORDER here is irrelevant to the bytes (JCS sorts keys), but it is kept
 * in spec order so a reader can diff it against the spec.
 */
final class SignedView
{
    /**
     * The literal the counterparty's `protocol_act_object()` supplies, and
     * therefore the only value whose canonical bytes agree with theirs. It is
     * not read from the act and is never on the wire: the version is a
     * statement about the signing rules, not about the message.
     */
    public const PROTOCOL_VERSION = '0.2';

    private function __construct() {}

    /**
     * All nine keys, ALWAYS — `null` where the act has no value, never
     * omitted. This is the one place the module's "absent, never null"
     * determinism rule does not apply: the counterparty's reference
     * `protocol_act_object()` returns the nine unconditionally, so an act with
     * no expiry is signed as `"expires_at":null`. Omitting it would diverge
     * the hash in both directions — our acts would not verify for them, and we
     * would reconstruct the wrong signed object for theirs. Their
     * implementation is the interop authority; the absent-not-null rule still
     * governs everything inside `terms` and our own records.
     *
     * @return array<string, mixed>
     */
    public static function of(Act $act): array
    {
        return [
            'protocol_version' => self::PROTOCOL_VERSION,
            'session_id' => $act->sessionId(),
            'round_number' => $act->roundNumber(),
            'sequence_number' => $act->sequenceNumber(),
            'message_type' => $act->messageType(),
            'sender_did' => $act->senderDid(),
            'timestamp' => $act->timestamp(),
            'expires_at' => $act->expiresAt(),
            'terms' => $act->terms(),
        ];
    }
}
