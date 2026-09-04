<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;

/**
 * The end-of-session audit log (spec section 10), for a session that reached a
 * terminal state without an acceptance.
 *
 * `ai_system_involved` is always true for this deployment: the negotiating
 * agent is an LLM under deterministic policy bounds.
 *
 * The chain-shape questions live in OfferSelection, the timeline in
 * SessionTimeline and the per-act rows in NegotiationLog — real seams shared
 * with TransactionRecord, not a suppression of this class's own complexity.
 */
final readonly class AuditLog
{
    private const VERSION = '0.1';

    public function __construct(
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
        string $outcome,
        AuditEvidence $evidence,
        string $generatedAt,
    ): array {
        $selection = new OfferSelection($acts);
        $timeline = new SessionTimeline($acts, $selection, $generatedAt);
        $oversight = $evidence->receipts !== [];

        return [
            'log_type' => 'a2cn_audit_log',
            'log_version' => self::VERSION,
            'log_id' => SessionId::derive($selection->sessionId . ':audit'),
            'session_id' => $selection->sessionId,
            'record_id' => null,
            'generated_at' => $generatedAt,
            'session_outcome' => $outcome,
            'parties' => [
                'initiator' => $parties->initiator->toArray(),
                'responder' => $parties->responder->toArray(),
            ],
            'session_timeline' => $timeline->toArray(),
            'negotiation_log' => NegotiationLog::of($acts),
            'protocol_violations' => array_map(
                static fn(ProtocolViolation $violation): array => $violation->toArray(),
                $evidence->violations,
            ),
            'offer_chain_hash' => $this->chainHash->of($acts),
            'audit_metadata' => [
                'ai_system_involved' => true,
                'human_oversight_present' => $oversight,
                'autonomous_decision' => !$oversight,
                'human_approval_receipts' => array_map(
                    static fn(ApprovalReceipt $receipt): array => $receipt->toArray(),
                    $evidence->receipts,
                ),
            ],
        ];
    }
}
