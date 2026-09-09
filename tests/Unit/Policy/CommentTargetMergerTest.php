<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\CommentTargetMerger;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\StructuralAsks;
use PHPUnit\Framework\TestCase;

/**
 * `adopted()` is the merger's own decision, published so the pipeline can
 * mirror exactly the targets the policy layer will price against — never the
 * raw extraction. A number displayed on the line that the agent did not price
 * against is worse than no number at all.
 */
final class CommentTargetMergerTest extends TestCase
{
    public function testACommentTargetIsAdoptedWhenTheLineCarriesNoBuyerAsk(): void
    {
        $adopted = (new CommentTargetMerger())->adopted(
            self::snapshot('open', requestedUnitPrice: null),
            self::interpretationAsking(15.0),
        );

        self::assertSame(['line-1' => 15.0], $adopted);
    }

    public function testACommentTargetIsNotAdoptedWhenAStructuredAskAlreadyStands(): void
    {
        $adopted = (new CommentTargetMerger())->adopted(
            self::snapshot('replied', requestedUnitPrice: 15.0),
            self::interpretationAsking(14.0),
        );

        self::assertSame([], $adopted);
    }

    public function testACommentTargetWinsOverAStructuredAskInARenegotiationRound(): void
    {
        $adopted = (new CommentTargetMerger())->adopted(
            self::snapshot('change_requested', requestedUnitPrice: 15.0),
            self::interpretationAsking(14.0),
        );

        self::assertSame(['line-1' => 14.0], $adopted);
    }

    public function testATargetNamingALineTheQuoteDoesNotHaveIsNotAdopted(): void
    {
        $adopted = (new CommentTargetMerger())->adopted(
            self::snapshot('open', requestedUnitPrice: null),
            new CommentInterpretation(structural: new StructuralAsks(lineChanges: [new InterpretedLineChange(
                lineItemId: 'line-9',
                targetUnitPrice: 15.0,
            )])),
        );

        self::assertSame([], $adopted);
    }

    public function testNothingIsAdoptedWithoutAnInterpretation(): void
    {
        self::assertSame([], (new CommentTargetMerger())->adopted(self::snapshot('open', null), null));
    }

    /** `merge()` still answers what it always answered, off the same decision. */
    public function testMergeAppliesExactlyTheAdoptedTargets(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshot('open', requestedUnitPrice: null),
            self::interpretationAsking(15.0),
        );

        self::assertSame(15.0, $merged->lines[0]->requestedUnitPrice);
    }

    /**
     * An ask that changes no line still rescales the buyer's target off the
     * asks that DO stand. QuoteDeciderTest's "structured field wins" fixture
     * turns on this: without the rescale the 15.00 structured ask never
     * becomes a quote-level percentage and an out-of-authority ask reads as
     * auto-reply.
     */
    public function testMergeRescalesTheBuyerTargetEvenWhenNoTargetIsAdopted(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshot('replied', requestedUnitPrice: 15.0),
            self::interpretationAsking(14.0),
        );

        self::assertSame(15.0, $merged->lines[0]->requestedUnitPrice, 'the stale ask still stands');
        self::assertSame(15.0, $merged->buyerTargetNet);
    }

    private static function interpretationAsking(float $target): CommentInterpretation
    {
        return new CommentInterpretation(structural: new StructuralAsks(lineChanges: [new InterpretedLineChange(
            lineItemId: 'line-1',
            targetUnitPrice: $target,
        )]));
    }

    private static function snapshot(string $state, ?float $requestedUnitPrice): QuoteSnapshot
    {
        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 19.99,
            lines: [
                new QuoteLineSnapshot(
                    identity: new QuoteLineIdentity('line-1', 'Widget'),
                    quantity: 1,
                    unitPriceNet: 19.99,
                    totalNet: 19.99,
                    requestedUnitPrice: $requestedUnitPrice,
                ),
            ],
            lifecycle: new QuoteLifecycle(stateTechnicalName: $state),
        );
    }
}
