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

    /** @return array<string, mixed> */
    public static function of(Act $act): array
    {
        $view = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'session_id' => $act->sessionId(),
        ];

        $round = $act->roundNumber();
        if ($round !== null) {
            $view['round_number'] = $round;
        }

        $view['sequence_number'] = $act->sequenceNumber();
        $view['message_type'] = $act->messageType();
        $view['sender_did'] = $act->senderDid();
        $view['timestamp'] = $act->timestamp();

        $expiresAt = $act->expiresAt();
        if ($expiresAt !== null) {
            $view['expires_at'] = $expiresAt;
        }

        $terms = $act->terms();
        if ($terms !== null) {
            $view['terms'] = $terms;
        }

        return $view;
    }
}
