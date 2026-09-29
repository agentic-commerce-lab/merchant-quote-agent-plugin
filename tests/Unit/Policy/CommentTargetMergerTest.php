<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\CommentTargetMerger;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\InterpretedLineChange;
use MerchantQuoteAgentPlugin\Policy\Data\PriceAsk;
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
 *
 * @mago-expect lint:too-many-methods
 * `distributedAcrossLines()` (#165's quote-wide mirror) is a second public
 * behaviour on this class, and it earns its own cases the same way `merge()`
 * and `adopted()` already did — the count tracks the class's own surface, not
 * unrelated concerns that belong elsewhere.
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

    /**
     * The mirror's need (#165): a quote-wide ask names no line, so it has
     * nothing for adopted() to answer, yet AskMirror still has to write
     * something onto every line. 15.00 target on a 19.99 line is a ~24.96%
     * cut, applied uniformly since there is only one line to apply it to.
     */
    public function testDistributedAcrossLinesScalesEveryLineByTheSameFactor(): void
    {
        $targets = (new CommentTargetMerger())->distributedAcrossLines(self::snapshot('open', null), 15.0);

        self::assertSame(['line-1' => 15.0], $targets);
    }

    public function testDistributedAcrossLinesIsANoOpOnAZeroTotal(): void
    {
        $snapshot = new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 0.0,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget'),
                quantity: 1,
                unitPriceNet: 0.0,
                totalNet: 0.0,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
        );

        self::assertSame([], (new CommentTargetMerger())->distributedAcrossLines($snapshot, 10.0));
    }

    public function testAStorefrontRequestedPriceBecomesTheBuyersTargetWithoutAComment(): void
    {
        // #223: with no comment nothing set buyerTargetNet, so the band
        // decider measured a 99.9% storefront ask as 0% and granted it.
        $merged = (new CommentTargetMerger())->merge(self::structured(1.0), null);

        self::assertSame(10.0, $merged->buyerTargetNet);
    }

    public function testARequestedPriceAboveTheQuotedOneIsNoAsk(): void
    {
        // Review Focus 1: a requested price above the quote is not a request
        // for a markup; the snapshot stays as it was.
        $merged = (new CommentTargetMerger())->merge(self::structured(120.0), null);

        self::assertNull($merged->buyerTargetNet);
    }

    public function testATargetAlreadySetIsNeverOverridden(): void
    {
        $merged = (new CommentTargetMerger())->merge(self::structured(1.0, buyerTargetNet: 900.0), null);

        self::assertSame(900.0, $merged->buyerTargetNet);
    }

    public function testACommentPriceAskKeepsTheStorefrontAskOutOfTheTarget(): void
    {
        // A later "can you do 5%?" on a line still holding an answered
        // storefront price must not stack on that stale figure (#223).
        $merged = (new CommentTargetMerger())->merge(
            self::structured(98.0),
            new CommentInterpretation(price: new PriceAsk(additionalDiscountPercent: 5.0)),
        );

        self::assertNull($merged->buyerTargetNet);
    }

    /** One 10 x 100.00 net line, optionally carrying a storefront requested price. */
    private static function structured(?float $requestedUnitPrice, ?float $buyerTargetNet = null): QuoteSnapshot
    {
        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: 1000.0,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1'),
                quantity: 10,
                unitPriceNet: 100.0,
                totalNet: 1000.0,
                requestedUnitPrice: $requestedUnitPrice,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
            buyerTargetNet: $buyerTargetNet,
        );
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
