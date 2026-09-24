<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Check\EvidenceInspector;

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
 *
 * `run()` is the gate chain — decide whether to publish at all — and delegates
 * the actual build/mirror/wire/receipt work to SellerActPublisher, a real seam
 * split from this class (see that class's docblock) rather than a workaround
 * for the per-class complexity gate.
 *
 * `$gateway` is nullable, defaulted, and last — matching ServiceQuoteHandler:
 * the container's only definition of QuoteGatewayInterface is
 * QuoteGatewayFactory::create(), which returns null when SwagCommercial's
 * classes exist but the shop is unlicensed. A non-nullable parameter here
 * would make the container pass null into a typed constructor argument and
 * raise a TypeError on construction — before observe()'s try/catch exists to
 * catch anything. An unlicensed shop is a configuration state, not our bug,
 * so SellerActPublisher::publish() handles it by returning null, not throwing.
 *
 * Not `final`: the tests substitute it (see ObserveQuoteHandlerTest).
 */
readonly class SellerActEmitter
{
    /** The only state in which an offer is visible to the buyer. */
    private const OFFER_VISIBLE_STATES = ['replied'];

    public function __construct(
        private SellerActFactory $acts,
        private EvidenceInspector $inspector,
        private ChainMirror $mirror,
        private SellerActJournal $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    public function observe(QuoteSnapshot $snapshot, \DateTimeImmutable $now): EmissionOutcome
    {
        try {
            $outcome = $this->run($snapshot, $now);
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

            $outcome = EmissionOutcome::failed();
        }

        $this->logger->outcome($snapshot, $outcome);

        return $outcome;
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

        // See ChainMirror: the whole chain, before any OTHER gate — including
        // identity resolution below — because a buyer act that never triggers
        // an emission must still reach our own copy. Identity failing to
        // resolve is exactly that case: real acts on the quote, nothing we can
        // sign into them yet.
        $this->mirror->mirror($quoteId, $chain);

        $identity = $this->acts->identityFor($snapshot);
        if ($identity === null) {
            $this->logger->warning('A2CN cannot identify this installation for a quote; no act emitted.', [
                'quoteId' => $quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
            ]);

            return EmissionOutcome::inert();
        }

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

        $act = $this->publisher()->publish($snapshot, $chain, $identity, $now);

        // publish() returns null only for an unlicensed shop (no gateway) —
        // a configuration state, not our bug, so inert() rather than failed().
        return $act === null ? EmissionOutcome::inert() : EmissionOutcome::emitted($act);
    }

    private function publisher(): SellerActPublisher
    {
        return new SellerActPublisher($this->acts, $this->mirror, $this->logger, $this->gateway);
    }
}
