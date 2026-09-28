<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use Psr\Log\LoggerInterface;

/**
 * What a verified write leaves `OfferRound` to do: reply with a reduction
 * percent, or escalate for a reason -- decided (and logged) here so
 * `OfferRound::play()` takes one branch on the answer instead of one per
 * reason. Split out the same way `ReductionForPass` was, to keep these
 * decisions off `OfferRound`'s class-scoped complexity budget.
 *
 * A write that moved nothing cannot tell "nothing left to give" from "a
 * retried pass whose first attempt already wrote this offer and then failed
 * to reply": both escalate as no_further_concession. The second fails safe —
 * see OfferApplier's docblock — because the human sees the offer on the quote.
 */
final class PostWriteOutcome
{
    private function __construct() {}

    /**
     * @param AppliedOffer $applied     a verified write; its `beforeNet` is
     *                                  `OfferApplier`'s own pre-write read --
     *                                  never the baseline, which can differ
     *                                  from both totals
     * @param float        $anchoredNet the baseline the percent is measured
     *                                  from (`SnapshotAdapter::anchored()`)
     *
     * @return array{0: ?float, 1: ?QuoteEscalationReason} the reduction
     *                                                      percent (null for a
     *                                                      hold), or the reason
     *                                                      to escalate instead
     */
    public static function of(LoggerInterface $logger, AppliedOffer $applied, float $anchoredNet): array
    {
        $quoteId = $applied->after->identity->quoteId;

        if (abs($applied->after->totals->totalNet - $applied->beforeNet) <= Epsilon::MONEY) {
            // The user's rule: a price ask is never answered with no
            // concession. One comparison covers every way to get here -- the
            // model held, the cap or the margin floor left nothing, an earlier
            // round already gave it -- and the write it follows changed no
            // price. A person decides whether to go further.
            $logger->info('This pass conceded nothing on a price ask; a human takes it.', [
                'quoteId' => $quoteId,
            ]);

            return [null, QuoteEscalationReason::NoFurtherConcession];
        }

        [$percent, $disagreed] = ReductionForPass::of($anchoredNet, $applied->after->totals->totalNet);

        if ($disagreed) {
            // The never-raise check only escalates a write BEFORE
            // $applied->verified is trusted; it cannot un-write one that
            // already landed (OfferApplier never rolls back — see its own
            // docblock). Reaching here means that check did not catch an
            // increase and the bad write already landed on the quote. This
            // must escalate rather than let ReductionForPass's caught
            // NegativeReduction have propagated: that exception is not
            // ModelUnavailable|CrossCustomerRead, so uncaught it would leave
            // NegotiationPipeline::run() unhandled, and ServiceQuoteHandler
            // rethrows after clearing the attempt counter — Messenger would
            // redeliver against a quote that still carries the write, with no
            // reply ever reaching the buyer. Escalating instead routes it
            // through the exact same funnel a verification failure already
            // uses: a human sees that the database disagrees with what this
            // pass applied.
            $logger->error('The figure to report disagreed with the write that already landed; escalating instead of replying.', [
                'quoteId' => $quoteId,
            ]);

            return [null, QuoteEscalationReason::VerificationFailed];
        }

        return [$percent, null];
    }
}
