<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use MerchantQuoteAgentPlugin\Policy\MarginFloorClamp;
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
        private MarginFloorGuard $marginFloors,
    ) {}

    public function apply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        QuoteAgentSettings $settings,
        ProposedOffer $offer,
    ): AppliedOffer {
        $quoteId = $snapshot->identity->quoteId;
        $limits = $settings->policy->price;

        $writes = $this->claim($gateway, $quoteId) ? ['claim'] : [];

        // Read fresh, right before the write: the state the offer is
        // actually measured against, not whatever $snapshot looked like when
        // this round started (interpreting, deciding and proposing all take
        // time, and none of it holds a lock on the quote) — and, since claim()
        // itself is a write that bumps the quote's revision, the only
        // revision the write below can safely assume as unchanged.
        $reference = $gateway->fetchSnapshot($quoteId);
        $live = SnapshotAdapter::toPolicy($reference);

        // The minimum-margin floor (spec 2026-09-24). Whether it binds is
        // measured on the LIVE quote, because that is what a percentage write
        // acts on; a null clamp means nothing binds and the offer goes out
        // exactly as proposed. Once it binds, a quote-wide ask is converted
        // into line prices from the baseline-anchored reference (the same one
        // the verifier reads below), so a repeated ask or a retried pass
        // reproduces round one's prices instead of compounding on them.
        $floors = $this->marginFloors->floors($live, $limits);
        $anchored = QuoteBaseline::read($reference)?->anchor($live) ?? $live;
        $floored = MarginFloorClamp::clamp($offer, $live->lines, $anchored->lines, $floors);
        if ($floored !== null) {
            $this->logger->info('The offer was raised to the minimum-margin floor.', ['quoteId' => $quoteId]);
        }

        array_push($writes, ...$this->write(
            $gateway,
            $reference,
            $limits,
            OfferWrite::of($offer, $floored, $reference),
        ));
        $gateway->recalculate($quoteId);
        $writes[] = 'recalculate';

        $after = $gateway->fetchSnapshot($quoteId);
        $violations = $this->verifier->verify(new VerifyOfferInput(
            // #49: the baseline when the quote has one, so the line check, the
            // totals check and NetFactor's normalisation are all anchored to
            // the original prices. On the first pass there is none and the
            // pre-write snapshot IS the original, so this degrades correctly.
            //
            // #54: anchor(), not a second builder of its own. The applier used
            // to read the STORED lines while the proposer read them merged
            // with the live ones, so a line added after the stamp was bounded
            // on one side and skipped entirely on the other.
            reference: $anchored,
            final: SnapshotAdapter::toPolicy($after),
            limits: $limits,
            now: new \DateTimeImmutable(),
            floors: $floors,
        ));

        // #174: the checks above bound the write against the BASELINE, by
        // design -- #49 needs that anchor so a per-round discount cannot
        // compound past maxDiscountPercent. They say nothing about the
        // quote's CURRENT total, and an earlier round or a human can have
        // moved that below the baseline already. Quote 1039: baseline
        // 2114.56, current 1818.20, "5% off" prices the offer at 2008.83 --
        // a legal discount against the baseline and a 190.63 INCREASE against
        // what the buyer's quote showed a moment ago. That must never be
        // REPORTED to the buyer as a concession.
        //
        // This is DETECTION, not prevention: by this point $after is already
        // the database's own post-write read, so the raised total has already
        // landed -- there is no rollback (see this class's own docblock), the
        // same as any other verification violation. What this buys is that
        // the pass is marked unverified and escalates to a human instead of
        // reporting the raise to the buyer as a discount.
        //
        // Checked here, deterministically, rather than in OfferAuthorizer or
        // OfferVerifier: $reference, fetched immediately above right before
        // the write, is the only current total either of those ever sees --
        // both work from the baseline-anchored snapshot. This is also the one
        // place every write passes through, per-line or quote-wide alike, so
        // one check here covers both instead of one per call site.
        //
        // Escalates like any other violation, and does NOT clamp: clamping
        // the FIGURE REPORTED for the write to $reference's total would hide
        // the very defect that produced it -- the offer was priced wrong
        // upstream, and silently capping the number here means nobody ever
        // finds out, while the raised total sits unfixed on the quote.
        if ($after->totals->totalNet > ($reference->totals->totalNet + Epsilon::MONEY)) {
            $violations[] = sprintf(
                'the write raised the total from %.2f to %.2f, above what the quote showed before this pass',
                $reference->totals->totalNet,
                $after->totals->totalNet,
            );
        }

        $applied = new AppliedOffer($violations === [], $violations, $after, $reference->totals->totalNet);
        $this->recorder->recordApplied($applied, $writes);

        return $applied;
    }

    /**
     * `process` moves the quote to in_review. A retry finds it already there
     * and the machine refuses — which is the correct outcome, not a failure:
     * the transition is bookkeeping and the offer is the substance.
     *
     * @return bool whether the transition actually happened, for the audit trail
     */
    private function claim(QuoteGatewayInterface $gateway, string $quoteId): bool
    {
        try {
            $gateway->transition($quoteId, QuoteTransition::Process);

            return true;
        } catch (IllegalTransitionException $e) {
            $this->logger->info('The quote was already claimed; continuing with the offer.', [
                'quoteId' => $quoteId,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * A per-line offer writes absolute unit prices to the named lines; a
     * quote-wide offer writes an absolute discount percentage (OfferWrite
     * says which). Never a delta — a retry must reproduce the same quote
     * rather than stack a second discount onto the first. Only the first
     * write of the pass carries the revision precondition; a buyer edit
     * between our read and our write must lose, loudly, exactly once.
     *
     * Known crash window on a floored write (spec 2026-09-24): if the pass
     * dies after updateLineItems and before updateQuote resets the quote
     * discount to 0%, the retry reads the line at its floor with the old
     * discount still stacked on it. The floor's min(…, today's price) then
     * accepts that as today's price, so the stacked discount stays below the
     * floor undetected until a human looks.
     *
     * @return list<string> the write names performed, for the audit trail
     */
    private function write(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $reference,
        QuoteLimits $limits,
        OfferWrite $write,
    ): array {
        $quoteId = $reference->identity->quoteId;
        $expected = $reference->revision;
        $writes = [];

        // #49: the snapshot read immediately above is the pre-negotiation
        // state on the first pass that writes anything, so the baseline rides
        // in the update this method already issues. A pass that escalates
        // writes nothing and stores nothing, which is correct — nothing
        // changed, so the next pass's prices are still the original ones.
        //
        // #54: and a line added since the stamp is appended to it here, at the
        // price this pre-write read gives it. Still no extra write: this
        // method's updateQuote goes out either way.
        $fragment = QuoteBaseline::stampOrExtend($reference);

        if ($write->lines !== []) {
            $gateway->updateLineItems($quoteId, array_map(self::lineChange(...), $write->lines), $expected);
            $expected = null;
            $writes[] = 'updateLineItems';
        }

        $gateway->updateQuote(
            $quoteId,
            new QuoteUpdate(
                discount: $write->discount,
                expiresAt: new \DateTimeImmutable(sprintf('+%d days', $limits->validityDays)),
                customFields: $fragment === [] ? null : $fragment,
            ),
            $expected,
        );
        $writes[] = 'updateQuote';

        return $writes;
    }

    private static function lineChange(QuoteLinePrice $price): QuoteLineItemChange
    {
        return new QuoteLineItemChange(lineItemId: $price->lineItemId, unitPriceNet: $price->unitPriceNet);
    }
}
