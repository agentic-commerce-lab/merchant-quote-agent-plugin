<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;

/**
 * The end-of-session transaction record (spec section 10), for a session that
 * reached an acceptance.
 *
 * A pure derivation over the chain, which is the point: producing it ourselves
 * is what proves our hashes match the counterparty's. Nothing is cached — a
 * stored record could go stale against its own chain.
 *
 * The chain-shape questions (which act was the final offer, what the session
 * id is) live in OfferSelection, and the "read a field off an act that might
 * not exist" pattern lives in OptionalAct — both real seams shared with
 * AuditLog, not a suppression of this class's own complexity.
 */
final readonly class TransactionRecord
{
    private const VERSION = '0.1';

    public function __construct(
        private ProtocolHash $hash,
        private OfferChainHash $chainHash,
    ) {}

    /**
     * @param list<Act> $acts
     *
     * @return array<string, mixed>
     */
    public function build(
        RecordParties $parties,
        array $acts,
        Act $acceptance,
        RecordSubject $subject,
        string $generatedAt,
    ): array {
        $selection = new OfferSelection($acts);

        $record = [
            'record_type' => 'a2cn_transaction_record',
            'record_version' => self::VERSION,
            'record_id' => SessionId::derive($selection->sessionId),
            'session_id' => $selection->sessionId,
            'generated_at' => $generatedAt,
            'parties' => [
                'initiator' => $parties->initiator->toArray(),
                'responder' => $parties->responder->toArray(),
            ],
            'deal_type' => $subject->dealType,
            'currency' => $subject->currency,
            'subject' => $subject->subject,
            'subject_reference' => $subject->subjectReference,
            'agreed_terms' => self::agreedTerms($selection->finalOffer),
            'negotiation_summary' => [
                'total_rounds' => \count($selection->offers),
                'total_messages' => \count($acts),
                'session_created_at' => OptionalAct::stringOr(
                    $selection->firstAct,
                    static fn(Act $act): string => $act->timestamp(),
                    $generatedAt,
                ),
                'first_offer_at' => OptionalAct::stringOr(
                    $selection->firstOffer,
                    static fn(Act $act): string => $act->timestamp(),
                    $generatedAt,
                ),
                'accepted_at' => $acceptance->timestamp(),
                'initiating_party_did' => $parties->initiator->did,
                'accepting_party_did' => $acceptance->senderDid(),
            ],
            'final_offer' => [
                'message_id' => OptionalAct::stringOr(
                    $selection->finalOffer,
                    static fn(Act $act): string => $act->messageId(),
                    '',
                ),
                'sender_did' => OptionalAct::stringOr(
                    $selection->finalOffer,
                    static fn(Act $act): string => $act->senderDid(),
                    '',
                ),
                'protocol_act_hash' => OptionalAct::stringOr(
                    $selection->finalOffer,
                    static fn(Act $act): string => $act->hash(),
                    '',
                ),
                'protocol_act_signature' => OptionalAct::stringOr(
                    $selection->finalOffer,
                    static fn(Act $act): string => $act->signature(),
                    '',
                ),
            ],
            'final_acceptance' => [
                'message_id' => $acceptance->messageId(),
                'sender_did' => $acceptance->senderDid(),
                'accepted_protocol_act_hash' => OptionalAct::stringOr(
                    $selection->finalOffer,
                    static fn(Act $act): string => $act->hash(),
                    '',
                ),
                'acceptance_signature' => $acceptance->signature(),
            ],
            'offer_chain_hash' => $this->chainHash->of($acts),
            // Hashed with this field blank, so a third party can recompute it
            // by blanking it again.
            'record_hash' => '',
        ];

        $record['record_hash'] = $this->hash->of($record);

        return $record;
    }

    /** @return array<string, mixed> */
    private static function agreedTerms(?Act $finalOffer): array
    {
        return $finalOffer?->terms() ?? [];
    }
}
