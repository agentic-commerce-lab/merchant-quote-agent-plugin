<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\DeliveryAsk;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationAsks;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
use MerchantQuoteAgentPlugin\Policy\Data\StructuralAsks;
use PHPUnit\Framework\TestCase;

final class InterpretedAskTest extends TestCase
{
    public function testAnAskNeedsClarificationOnlyWhenTheModelAskedSomething(): void
    {
        self::assertTrue($this->ask(['which line do you mean?'])->needsClarification());
        self::assertFalse($this->ask([])->needsClarification());

        // A populated price ask alongside a question is still ambiguous: the
        // question is the model saying it could not place that ask.
        $withPrice = new InterpretedAsk(
            new CommentInterpretation(
                price: new PriceAsk(additionalDiscountPercent: 5.0),
                clarificationQuestions: ['which line do you mean?'],
            ),
            'hash',
        );
        self::assertTrue($withPrice->needsClarification());
    }

    public function testAClarificationIsNotAnAnswerToTheBuyer(): void
    {
        // Load-bearing: QuoteEscalator::releaseFor() clears the escalation
        // marker only for an outcome that answered, so a clarification must
        // not clear an escalated quote's marker.
        self::assertFalse(NegotiationOutcome::Clarified->answeredTheBuyer());
        self::assertTrue(NegotiationOutcome::Offered->answeredTheBuyer());
        self::assertTrue(NegotiationOutcome::Countered->answeredTheBuyer());

        // Same load: HandedOver found a human already on the quote and wrote
        // nothing, so both QuoteEscalator::releaseFor() and
        // ClarificationMarker::releaseFor() must keep releasing nothing for
        // it. If this ever flips true, a stand-down starts releasing the
        // escalation marker and the next pass re-escalates a quote a human
        // is holding.
        self::assertFalse(NegotiationOutcome::HandedOver->answeredTheBuyer());

        // An acknowledgement restates the standing quote; it is not an offer.
        // True here would release the escalation and clarification markers
        // and put a figure-less pass in the admin's "Agent Offer" column.
        self::assertFalse(NegotiationOutcome::Acknowledged->answeredTheBuyer());
    }

    public function testAnAskIsNotStructuralWhenQuantityMatchesExistingSnapshot(): void
    {
        $snapshot = NegotiationFixture::snapshot();
        $ask = new InterpretedAsk(new CommentInterpretation(structural: new StructuralAsks(lineChanges: [
            new InterpretedLineChange(lineItemId: 'line-1', quantity: 10, targetUnitPrice: 760.0),
        ])), 'hash');

        self::assertFalse($ask->isStructural($snapshot));
    }

    public function testAnAskIsStructuralWhenQuantityDiffersFromExistingSnapshot(): void
    {
        $snapshot = NegotiationFixture::snapshot();
        $ask = new InterpretedAsk(new CommentInterpretation(structural: new StructuralAsks(lineChanges: [
            new InterpretedLineChange(lineItemId: 'line-1', quantity: 20, targetUnitPrice: 760.0),
        ])), 'hash');

        self::assertTrue($ask->isStructural($snapshot));
    }

    public function testAnAskIsStructuralWhenLineIsRemoved(): void
    {
        $snapshot = NegotiationFixture::snapshot();
        $ask = new InterpretedAsk(new CommentInterpretation(structural: new StructuralAsks(lineChanges: [
            new InterpretedLineChange(lineItemId: 'line-1', remove: true),
        ])), 'hash');

        self::assertTrue($ask->isStructural($snapshot));
    }

    public function testAnAskIsStructuralWhenProductIsAdded(): void
    {
        $snapshot = NegotiationFixture::snapshot();
        $ask = new InterpretedAsk(
            new CommentInterpretation(structural: new StructuralAsks(addProducts: ['prod-new'])),
            'hash',
        );

        self::assertTrue($ask->isStructural($snapshot));
    }

    public function testAnAskIsStructuralWhenQuantitySpecifiedWithoutSnapshot(): void
    {
        $ask = new InterpretedAsk(new CommentInterpretation(structural: new StructuralAsks(lineChanges: [
            new InterpretedLineChange(lineItemId: 'line-1', quantity: 10),
        ])), 'hash');

        self::assertTrue($ask->isStructural());
    }

    public function testAFullyEmptyInterpretationHasNoAsk(): void
    {
        // #177's exact shape: every field null or empty -- the buyer said
        // "Nice, thanks!" and the extraction found nothing.
        self::assertTrue((new InterpretedAsk(new CommentInterpretation(), 'hash'))->hasNoAsk());
        // An empty clarificationQuestions list is not an ask on its own either.
        self::assertTrue($this->ask([])->hasNoAsk());
    }

    /**
     * Each of these, alone, is enough to make `hasNoAsk()` false -- the
     * boundary #177 is careful about: `bestPriceRequested` and `targetTotal`
     * are asks even with no specific figure attached to the other one, a
     * non-empty clarification question is already something to answer, a
     * human-review request and a non-price (delivery/payment/bundle) ask are
     * asks nothing here may treat as silence, and a structural line change is
     * an ask even with no comment-level price attached.
     */
    public function testAnyPopulatedFieldMeansThereIsAnAsk(): void
    {
        self::assertFalse(
            (new InterpretedAsk(
                new CommentInterpretation(price: new PriceAsk(bestPriceRequested: true)),
                'hash',
            ))->hasNoAsk(),
        );
        self::assertFalse(
            (new InterpretedAsk(
                new CommentInterpretation(price: new PriceAsk(targetTotal: 3500.0)),
                'hash',
            ))->hasNoAsk(),
        );
        self::assertFalse($this->ask(['which line do you mean?'])->hasNoAsk());
        self::assertFalse(
            (new InterpretedAsk(
                new CommentInterpretation(humanReviewRequests: ['talk to a person']),
                'hash',
            ))->hasNoAsk(),
        );
        self::assertFalse(
            (new InterpretedAsk(
                new CommentInterpretation(
                    negotiation: new NegotiationAsks(delivery: new DeliveryAsk(freeShipping: true)),
                ),
                'hash',
            ))->hasNoAsk(),
        );
        self::assertFalse((new InterpretedAsk(new CommentInterpretation(structural: new StructuralAsks(lineChanges: [
            new InterpretedLineChange(lineItemId: 'line-1', targetUnitPrice: 95.0),
        ])), 'hash'))->hasNoAsk());
    }

    /** @param list<string> $questions */
    private function ask(array $questions): InterpretedAsk
    {
        return new InterpretedAsk(new CommentInterpretation(clarificationQuestions: $questions), 'hash');
    }
}
