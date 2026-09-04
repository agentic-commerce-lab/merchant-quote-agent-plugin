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
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Store\ApprovalReceipt;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use Psr\Log\LoggerInterface;

/**
 * Builds, mirrors and writes the seller act once SellerActEmitter's gates
 * have all passed.
 *
 * Split out of SellerActEmitter along the same real seam as SellerActFactory
 * and ChainMirror: this is the "publish" half — build, mirror, wire, record
 * approval — as opposed to "decide whether to publish at all" (the gate chain
 * in SellerActEmitter::run()). Fixing #1 of review round 1 (a null gateway
 * must never crash construction) added exactly the branch that pushed
 * SellerActEmitter over the per-class complexity gate; this is that branch's
 * new home, not a workaround for the gate.
 *
 * `$gateway` is nullable: the container's only definition of
 * QuoteGatewayInterface is QuoteGatewayFactory::create(), which returns null
 * when SwagCommercial's classes exist but the shop is unlicensed. That is a
 * configuration state, not our bug — publish() reports it by returning null
 * rather than throwing, BEFORE building or mirroring an act nobody can write
 * to the wire.
 */
final readonly class SellerActPublisher
{
    public function __construct(
        private SellerActFactory $acts,
        private ChainMirror $mirror,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    /**
     * @throws \MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount
     * @throws \MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey
     * @throws UnbuildableAct
     */
    public function publish(
        QuoteSnapshot $snapshot,
        ActChain $chain,
        A2cnIdentity $identity,
        \DateTimeImmutable $now,
    ): ?Act {
        $gateway = $this->gateway;
        if ($gateway === null) {
            $this->logger->warning('A2CN cannot emit: no licensed quote gateway.', [
                'quoteId' => $snapshot->identity->quoteId,
            ]);

            return null;
        }

        $quoteId = $snapshot->identity->quoteId;
        $act = $this->acts->build($snapshot, $chain, $identity, $now);

        // Mirror before wire. If the write below fails, our mirror already
        // reflects the changed terms, so the next observation sees the terms as
        // changed and retries the append — instead of the wire holding one act,
        // the mirror another, and offer_chain_hash diverging permanently.
        $this->mirror->mirrorOne($quoteId, $act);

        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
            ActKey::for($act->sequenceNumber(), ActRole::Seller) => $act->raw(),
        ]));

        $this->recordApproval($snapshot, $act, $now);

        return $act;
    }

    /**
     * A receipt records that a HUMAN stood behind terms the agent itself would
     * have escalated. An unreleased escalation marker is exactly that state:
     * the agent escalated and has not answered since (QuoteEscalator releases
     * the marker only on a pass that answered), so the offer now on the quote
     * is a person's.
     *
     * Own try/catch, deliberately separate from SellerActEmitter::observe()'s:
     * the act is already mirrored AND on the wire, so a receipt failure must
     * not turn this into a failed emission — that would lie about whether the
     * offer went out. The receipt is genuinely lost for this call; logged, not
     * silently swallowed.
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
