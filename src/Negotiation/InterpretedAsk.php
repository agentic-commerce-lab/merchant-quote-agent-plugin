<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;

/**
 * What the buyer asked for, and the hash of the prompt that read it.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10); `isStructural()` and
 * `isLineChangeStructural()` alone already measure 8, with zero headroom.
 * `hasNoAsk()` below has to compare every field `NegotiationPipeline` would
 * otherwise read piecemeal -- #177's whole point is that missing even one of
 * them (a `targetTotal`, a `humanReviewRequests` entry) turns a real ask back
 * into a silent grant. Splitting it into a second method on this class saves
 * nothing, since the rule counts the class as a whole; moving it to AskGate
 * or NegotiationPipeline only relocates the same branches onto a class that
 * is at or over its own documented ceiling already (see NegotiationPipeline's
 * own class docblock).
 */
final readonly class InterpretedAsk
{
    public function __construct(
        public CommentInterpretation $interpretation,
        public string $promptHash,
    ) {}

    /**
     * True when the buyer asked to change WHAT is being sold rather than what
     * it costs. A line change carrying only a target PRICE is a price ask and
     * squarely in the mandate; a quantity change (different from the current line
     * item quantity), a removal or an added product is not.
     */
    public function isStructural(?QuoteSnapshot $snapshot = null): bool
    {
        if ($this->interpretation->structural->addProducts !== []) {
            return true;
        }

        $existingQuantities = [];
        if ($snapshot !== null) {
            foreach ($snapshot->content->lines as $line) {
                $existingQuantities[$line->identity->lineItemId] = $line->quantity;
            }
        }

        foreach ($this->interpretation->structural->lineChanges as $change) {
            if ($this->isLineChangeStructural($change, $existingQuantities, $snapshot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $existingQuantities
     */
    private function isLineChangeStructural(
        InterpretedLineChange $change,
        array $existingQuantities,
        ?QuoteSnapshot $snapshot,
    ): bool {
        if ($change->remove === true) {
            return true;
        }

        if ($change->quantity === null) {
            return false;
        }

        if ($snapshot === null) {
            return true;
        }

        return (
            !\array_key_exists($change->lineItemId, $existingQuantities)
            || $change->quantity !== $existingQuantities[$change->lineItemId]
        );
    }

    /**
     * True when the buyer asked for something other than a price — shipping or
     * payment terms. Nothing carries these past the interpreter:
     * NegotiationPipeline composes only `price` into the proposal, and the
     * gateway has no way to write a delivery or payment term onto a quote.
     *
     * A volume ask is NOT one of these; see NegotiationAsks on why it stopped
     * being one.
     */
    public function hasNonPriceAsk(): bool
    {
        return $this->interpretation->negotiation?->hasAny() === true;
    }

    /**
     * True when the model asked the buyer something instead of guessing. The
     * extract prompt reserves this for asks that are clear in intent but
     * ambiguous in reference — "10% off" on a five-line quote — so answering
     * one by picking a line is exactly the wrong move.
     */
    public function needsClarification(): bool
    {
        return $this->interpretation->clarificationQuestions !== [];
    }

    /**
     * True when the extraction carries no ask anywhere: no price ask, no
     * structural ask, no non-price ask, no clarification question and no
     * human-review request. This is the #177 shape -- the buyer wrote "Nice,
     * thanks!" to a quote a human merchant had already closed, the model ran
     * and correctly found nothing, and still returned a fully-shaped (if
     * empty) DTO rather than null. Reading `$ask !== null` alone as "there is
     * an ask" let that empty extraction reach the band and become an
     * unsolicited offer.
     *
     * ponytail: a lone `structural.validityUntilIsoDate` counts as an ask
     * here (so this returns false), even though nothing downstream besides
     * the admin decision display currently reads it. Narrowing "no ask"
     * further than the extraction's own shape would be scope creep beyond
     * #177, not a smaller diff.
     */
    public function hasNoAsk(): bool
    {
        $price = $this->interpretation->price;
        $structural = $this->interpretation->structural;

        return (
            $price->bestPriceRequested !== true
            && $price->additionalDiscountPercent === null
            && $price->targetTotal === null
            && $structural->lineChanges === []
            && $structural->addProducts === []
            && $structural->validityUntilIsoDate === null
            && $this->interpretation->clarificationQuestions === []
            && $this->interpretation->humanReviewRequests === []
            && !$this->hasNonPriceAsk()
        );
    }
}
