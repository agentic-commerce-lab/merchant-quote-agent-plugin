<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;
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

    /** @param list<string> $questions */
    private function ask(array $questions): InterpretedAsk
    {
        return new InterpretedAsk(new CommentInterpretation(clarificationQuestions: $questions), 'hash');
    }
}
