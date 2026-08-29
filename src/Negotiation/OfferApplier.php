<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Writes the offer, then asks the database what actually happened.
 *
 * Every write is ABSOLUTE — a unit price, a discount percentage, an expiry
 * date, never a delta — so re-running the whole pass after a crash produces
 * the same quote rather than compounding a second discount onto the first.
 *
 * A verification failure escalates and LEAVES THE CHANGES IN PLACE. Rolling
 * back is itself a fallible write with no transaction around it, and a failed
 * rollback leaves a third state nobody intended. We report what the database
 * says, which is the same principle the verifier exists to enforce.
 */
final readonly class OfferApplier
{
    public function __construct(
        private OfferVerifier $verifier,
        private LoggerInterface $logger,
        private DecisionRecorder $recorder,
    ) {}

    public function apply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteAgentSettings $settings,
        ProposedOffer $offer,
    ): AppliedOffer {
        $quoteId = $snapshot->identity->quoteId;
        $limits = $settings->policy->price;

        $writes = ['claim'];
        $this->claim($gateway, $quoteId);

        // Read fresh, right before the write: the state the offer is
        // actually measured against, not whatever $snapshot looked like when
        // this round started (interpreting, deciding and proposing all take
        // time, and none of it holds a lock on the quote) — and, since claim()
        // itself is a write that bumps the quote's revision, the only
        // revision the write below can safely assume as unchanged.
        $reference = $gateway->fetchSnapshot($quoteId);

        array_push($writes, ...$this->write($gateway, $quoteId, $reference->revision, $limits, $offer));
        $gateway->recalculate($quoteId);
        $writes[] = 'recalculate';

        $after = $gateway->fetchSnapshot($quoteId);
        $violations = $this->verifier->verify(new VerifyOfferInput(
            reference: SnapshotAdapter::toPolicy($reference),
            final: SnapshotAdapter::toPolicy($after),
            limits: $limits,
            now: new \DateTimeImmutable(),
        ));

        $applied = new AppliedOffer($violations === [], $violations, $after);
        $this->recorder->recordApplied($applied, $writes);

        return $applied;
    }

    /**
     * `process` moves the quote to in_review. A retry finds it already there
     * and the machine refuses — which is the correct outcome, not a failure:
     * the transition is bookkeeping and the offer is the substance.
     */
    private function claim(QuoteGatewayInterface $gateway, string $quoteId): void
    {
        try {
            $gateway->transition($quoteId, QuoteTransition::Process);
        } catch (IllegalTransitionException $e) {
            $this->logger->info('The quote was already claimed; continuing with the offer.', [
                'quoteId' => $quoteId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * A per-line offer writes absolute unit prices to the named lines; a
     * quote-wide offer writes an absolute discount percentage. Never both,
     * and never a delta — a retry must reproduce the same quote rather than
     * stack a second discount onto the first. Only the first write of the
     * pass carries the revision precondition; a buyer edit between our read
     * and our write must lose, loudly, exactly once.
     *
     * @return list<string> the write names performed, for the audit trail
     */
    private function write(
        QuoteGatewayInterface $gateway,
        string $quoteId,
        QuoteRevision $expected,
        QuoteLimits $limits,
        ProposedOffer $offer,
    ): array {
        $linePrices = $offer->price->linePricesNet;
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', $limits->validityDays));

        if ($linePrices !== null && $linePrices !== []) {
            $gateway->updateLineItems($quoteId, array_map(self::lineChange(...), $linePrices), $expected);
            $gateway->updateQuote($quoteId, new QuoteUpdate(expiresAt: $expiresAt));

            return ['updateLineItems', 'updateQuote'];
        }

        $gateway->updateQuote(
            $quoteId,
            new QuoteUpdate(
                discount: new Discount(DiscountType::Percentage, $offer->price->discountPercent ?? 0.0),
                expiresAt: $expiresAt,
            ),
            $expected,
        );

        return ['updateQuote'];
    }

    private static function lineChange(QuoteLinePrice $price): QuoteLineItemChange
    {
        return new QuoteLineItemChange(lineItemId: $price->lineItemId, unitPriceNet: $price->unitPriceNet);
    }
}
