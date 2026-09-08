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
 * On a released SwagCommercial a renegotiation round sits in `reopen`, not
 * `change_requested`, and there is no structured `requested_price` at all — so
 * the comment target is the only ask the agent will ever see. If the state name
 * is not recognised, `$commentWins` is false and a stale structured ask would
 * outrank it. On legacy there is no structured ask to be stale, so the visible
 * symptom would be a modern shop mid-migration, not an outright failure — which
 * is precisely why it needs a test rather than a code read.
 *
 * Build the snapshot and interpretation with the same shape
 * `tests/Unit/Policy/QuoteBandDeciderTest.php` exercises directly against the
 * `Policy\Data` constructors: no `CommentTargetMergerTest.php` exists in this
 * repo to copy from, so these two private helpers are built straight from
 * `QuoteSnapshot`, `QuoteLineSnapshot` and `CommentInterpretation`'s own
 * constructors rather than a parallel fixture shape.
 */
final class CommentTargetMergerLegacyStateTest extends TestCase
{
    public function testACommentTargetWinsInReopen(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshotInState('reopen', structuredAsk: 9.0),
            self::interpretationTargeting('line-1', 7.0),
        );

        self::assertSame(7.0, $merged->lines[0]->requestedUnitPrice);
    }

    public function testACommentTargetStillWinsInChangeRequested(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshotInState('change_requested', structuredAsk: 9.0),
            self::interpretationTargeting('line-1', 7.0),
        );

        self::assertSame(7.0, $merged->lines[0]->requestedUnitPrice);
    }

    public function testAStructuredAskStillWinsOutsideARenegotiationRound(): void
    {
        $merged = (new CommentTargetMerger())->merge(
            self::snapshotInState('open', structuredAsk: 9.0),
            self::interpretationTargeting('line-1', 7.0),
        );

        self::assertSame(9.0, $merged->lines[0]->requestedUnitPrice);
    }

    /** A 10.00 net single-line quote in the given state, carrying a structured ask. */
    private static function snapshotInState(string $state, float $structuredAsk): QuoteSnapshot
    {
        $total = 10.0;

        return new QuoteSnapshot(
            currencyIso: 'EUR',
            totalNet: $total,
            lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1'),
                quantity: 1,
                unitPriceNet: $total,
                totalNet: $total,
                requestedUnitPrice: $structuredAsk,
            )],
            lifecycle: new QuoteLifecycle(stateTechnicalName: $state),
        );
    }

    private static function interpretationTargeting(string $lineItemId, float $targetUnitPrice): CommentInterpretation
    {
        return new CommentInterpretation(structural: new StructuralAsks(lineChanges: [new InterpretedLineChange(
            lineItemId: $lineItemId,
            targetUnitPrice: $targetUnitPrice,
        )]));
    }
}
