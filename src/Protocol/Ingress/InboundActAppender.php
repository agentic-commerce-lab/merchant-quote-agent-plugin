<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Act\ActRole;
use MerchantQuoteAgentPlugin\Protocol\Emitter\ChainMirror;
use Psr\Log\LoggerInterface;

/**
 * Runs the three gates, then writes.
 *
 * Order is the whole design: gates first, then the WIRE, then our mirror.
 * ChainMirror is our independent copy of what the chain carries, so a mirror
 * row for an act the quote never received would be our own evidence claiming
 * something the counterparty can disprove. The wire write is therefore what
 * makes an act real; the mirror follows it and never precedes it.
 *
 * A replayed act — same `message_id`, already on the chain — is accepted
 * without a write. That is what makes the missing JWT replay store safe, and
 * it is what lets a buyer retry a request whose response they never saw.
 *
 * Concurrency is the CALLER's job, exactly as it is for SellerActEmitter: the
 * controller holds the per-quote servicing lock, because two overlapping
 * appends would both read the same `nextSequence()` and the second would
 * overwrite the first — the role suffix in the act key protects buyer from
 * seller, not buyer from buyer.
 */
final readonly class InboundActAppender
{
    public function __construct(
        private InboundActConformance $conformance,
        private ChainMirror $mirror,
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    public function append(InboundActRequest $request): InboundActRefusal|Act
    {
        $refusal = InboundActEnvelope::refusal(
            $request->act,
            $request->sessionId,
            $request->issuerDid,
        ) ?? InboundActEligibility::refusal($request->act, $request->chain, $request->quote, $request->sellerDid);
        if ($refusal !== null) {
            return $refusal;
        }

        $existing = self::alreadyOnChain($request);
        if ($existing !== null) {
            return $existing;
        }

        $refusal = $this->conformance->refusal($request->act, $request->chain);
        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->gateway === null) {
            return new InboundActRefusal(503, 'quote_backend_unavailable');
        }

        return $this->write($request);
    }

    private function write(InboundActRequest $request): InboundActRefusal|Act
    {
        $key = ActKey::for($request->act->sequenceNumber(), ActRole::Buyer);

        try {
            $this->gateway?->updateQuote($request->quoteId, new QuoteUpdate(customFields: [
                ActKey::SESSION_KEY => $request->sessionId,
                $key => $request->act->raw(),
            ]));
        } catch (\Throwable $error) {
            $this->logger->error('A2CN could not write an inbound act to the quote.', [
                'quoteId' => $request->quoteId,
                'exception' => $error,
            ]);

            return new InboundActRefusal(502, 'act_not_stored');
        }

        $this->mirror->mirrorOne($request->quoteId, $request->act, ActRole::Buyer);

        return $request->act;
    }

    /**
     * The same act, already on the chain. Matched on `message_id`, which the
     * counterparty owns and does not reuse — not on the sequence, because a
     * replay carries the sequence it was first accepted at and would
     * otherwise read as a conflict.
     */
    private static function alreadyOnChain(InboundActRequest $request): ?Act
    {
        foreach ($request->chain->acts() as $act) {
            if ($act->messageId() === $request->act->messageId()) {
                return $act;
            }
        }

        return null;
    }
}
