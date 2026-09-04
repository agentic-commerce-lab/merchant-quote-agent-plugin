<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use Psr\Log\LoggerInterface;

/**
 * The single place a seller act is produced.
 *
 * The trigger is a STATE TRANSITION, not an agent decision: the quote entered a
 * buyer-visible offer state and its terms differ from our last signed act. That
 * one rule covers the agent's own offer, a human's post-escalation edit, and any
 * future path that writes an offer — hooking the negotiation pipeline would miss
 * the human entirely.
 *
 * Idempotent by construction (it appends only on a terms change), so it is safe
 * to call from more than one site. Concurrency is the CALLER's job:
 * ObserveQuoteHandler holds the per-quote servicing lock, because two
 * overlapping runs would both compute the same next sequence and race two
 * different signed payloads onto the same wire key.
 *
 * Fail-open throughout: an evidence failure never stops commerce.
 */
readonly class SellerActEmitter
{
    /** The only state in which an offer is visible to the buyer. */
    private const OFFER_VISIBLE_STATES = ['replied'];

    public function __construct(
        private SellerActFactory $acts,
        private EvidenceInspector $inspector,
        private ChainMirror $mirror,
        private QuoteGatewayInterface $gateway,
        private LoggerInterface $logger,
    ) {}

    public function observe(QuoteSnapshot $snapshot, \DateTimeImmutable $now): EmissionOutcome
    {
        try {
            return $this->run($snapshot, $now);
        } catch (\Throwable $error) {
            // Fail-open on commerce: an evidence failure never stops servicing.
            // Deliberately NOT persisted as a protocol violation — this is a
            // throw from our own signing, store or gateway code, and recording
            // it as evidence against the counterparty would mislead a legal
            // reader of the audit log.
            $this->logger->error('A2CN act emission failed.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $error,
            ]);

            return EmissionOutcome::failed();
        }
    }

    /**
     * Every exception this (and everything it calls) can throw is caught by
     * observe() above; this docblock documents them rather than catching here,
     * so the fail-open boundary stays in exactly one place.
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey
     * @throws \MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount
     * @throws UnbuildableAct
     */
    private function run(QuoteSnapshot $snapshot, \DateTimeImmutable $now): EmissionOutcome
    {
        $quoteId = $snapshot->identity->quoteId;
        $chain = ActChain::read($snapshot->lifecycle->customFields);
        if ($chain->isEmpty()) {
            // No session, or no readable act: nobody is negotiating with us
            // over A2CN, and we do not open a session unilaterally.
            return EmissionOutcome::inert();
        }

        $identity = $this->acts->identityFor($snapshot);
        if ($identity === null) {
            $this->logger->warning('A2CN cannot identify this installation for a quote; no act emitted.', [
                'quoteId' => $quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
            ]);

            return EmissionOutcome::inert();
        }

        // See ChainMirror: the whole chain, before any gate, because a buyer act
        // that never triggers an emission must still reach our own copy.
        $this->mirror->mirror($quoteId, $chain);

        if (!\in_array($snapshot->lifecycle->stateTechnicalName, self::OFFER_VISIBLE_STATES, strict: true)) {
            return EmissionOutcome::unchanged();
        }

        $terms = $this->acts->terms($snapshot);
        if ($this->acts->termsUnchanged($chain->lastSellerAct($identity->did)?->terms(), $terms)) {
            return EmissionOutcome::unchanged();
        }

        $violation = $this->inspector->firstViolation($chain, $snapshot, $identity->did, $now);
        if ($violation !== null) {
            $this->mirror->recordViolation($quoteId, $violation);
            $this->logger->warning('A2CN refused to sign into a chain.', [
                'quoteId' => $quoteId,
                'violationType' => $violation->violationType,
                'description' => $violation->description,
            ]);

            return EmissionOutcome::violation($violation);
        }

        return $this->emit($snapshot, $chain, $identity, $now);
    }

    private function emit(
        QuoteSnapshot $snapshot,
        ActChain $chain,
        A2cnIdentity $identity,
        \DateTimeImmutable $now,
    ): EmissionOutcome {
        $quoteId = $snapshot->identity->quoteId;
        $act = $this->acts->build($snapshot, $chain, $identity, $now);

        // Mirror before wire. If the write below fails, our mirror already
        // reflects the changed terms, so the next observation sees the terms as
        // changed and retries the append — instead of the wire holding one act,
        // the mirror another, and offer_chain_hash diverging permanently.
        $this->mirror->mirrorOne($quoteId, $act);

        $this->gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ActKey::for($act->sequenceNumber(), ActRole::Seller) => $act->raw(),
        ]));

        $this->recordApproval($snapshot, $act, $now);

        return EmissionOutcome::emitted($act);
    }

    /**
     * A receipt records that a HUMAN stood behind terms the agent itself would
     * have escalated. An unreleased escalation marker is exactly that state:
     * the agent escalated and has not answered since (QuoteEscalator releases
     * the marker only on a pass that answered), so the offer now on the quote
     * is a person's.
     *
     * Own try/catch, deliberately separate from observe()'s: the act is already
     * mirrored AND on the wire, so a receipt failure must not turn this into a
     * failed emission — that would lie about whether the offer went out. The
     * receipt is genuinely lost for this call; logged, not silently swallowed.
     */
    private function recordApproval(QuoteSnapshot $snapshot, Act $act, \DateTimeImmutable $now): void
    {
        $marker = $snapshot->lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null;
        if (!\is_string($marker) || $marker === '') {
            return;
        }

        try {
            $this->mirror->recordReceipt(
                $snapshot->identity->quoteId,
                new ApprovalReceipt(
                    receiptId: $act->sessionId() . ':' . $act->hash(),
                    offerHash: $act->hash(),
                    thresholdCrossed: $marker,
                    approvedAt: $now->format(\DATE_ATOM),
                ),
            );
        } catch (\Throwable $error) {
            $this->logger->error('A2CN approval receipt lost; the act was already emitted.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $error,
            ]);
        }
    }
}
