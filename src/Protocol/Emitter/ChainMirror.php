<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;

/**
 * Mirrors the wire chain into our own copy.
 *
 * Mirrors the WHOLE known chain, not just what is about to be signed: this is
 * our only independent copy, so a buyer act that never triggers an emission
 * (terms unchanged, a violation, a state we do not counter into) must still
 * land here or it never reaches the mirror at all.
 *
 * Everything is keyed under the session DERIVED from the quote, never each
 * act's own claimed `session_id` — a misbehaving counterparty could forge that,
 * and our evidence would be filed under their session.
 */
final readonly class ChainMirror
{
    public function __construct(
        private ActStoreInterface $store,
    ) {}

    public function mirror(string $quoteId, ActChain $chain): void
    {
        foreach ($chain->acts() as $act) {
            $this->mirrorOne($quoteId, $act);
        }
    }

    public function mirrorOne(string $quoteId, Act $act): void
    {
        // Each act's OWN sequence, not a running counter, so this stays correct
        // however many rounds have already been mirrored. append() is
        // idempotent, so re-mirroring is free.
        $this->store->append(new ActRecord(
            sessionId: SessionId::forQuote($quoteId),
            quoteId: $quoteId,
            sequence: $act->sequenceNumber(),
            act: $act,
        ));
    }

    public function recordViolation(string $quoteId, ProtocolViolation $violation): void
    {
        $this->store->appendViolation(SessionId::forQuote($quoteId), $violation);
    }

    public function recordReceipt(string $quoteId, ApprovalReceipt $receipt): void
    {
        $this->store->appendReceipt(SessionId::forQuote($quoteId), $receipt);
    }
}
