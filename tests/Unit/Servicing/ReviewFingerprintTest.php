<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\MirroredAsk;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

final class ReviewFingerprintTest extends TestCase
{
    /**
     * The read model hides AskMirror's own line write. Both the pass snapshot
     * and the later live read therefore expose only buyer-originated asks.
     */
    public function testAMirroredAskDoesNotMakeTheDraftStale(): void
    {
        $comment = QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000');
        $serviced = QuoteSnapshotFixture::snapshot(comments: [$comment], lines: [QuoteSnapshotFixture::line(null)]);
        $mirrored = QuoteSnapshotFixture::snapshot(comments: [$comment], lines: [QuoteSnapshotFixture::line(null)]);

        self::assertSame(ServicingFingerprint::of($mirrored), ServicingFingerprint::review($serviced, $mirrored));
    }

    public function testStructuredAskMirroredByTheAgentDoesNotMakeTheDraftStale(): void
    {
        $serviced = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(9.0)]);
        $mirrored = QuoteSnapshotFixture::snapshot(
            customFields: MirroredAsks::stamp([], ['line-1' => MirroredAsk::written(9.0, 1.0)]),
            lines: [QuoteSnapshotFixture::line(null)],
        );

        self::assertSame(
            ServicingFingerprint::of($mirrored),
            ServicingFingerprint::review($serviced, $mirrored, ['line-1']),
        );
    }

    public function testNewMirrorOverAnOlderStructuredAskDoesNotMakeTheDraftStale(): void
    {
        $serviced = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(8.0)]);
        $mirrored = QuoteSnapshotFixture::snapshot(
            customFields: MirroredAsks::stamp([], ['line-1' => MirroredAsk::written(7.0, 1.0)]),
            lines: [QuoteSnapshotFixture::line(null)],
        );

        self::assertSame(
            ServicingFingerprint::of($mirrored),
            ServicingFingerprint::review($serviced, $mirrored, ['line-1']),
        );
    }

    public function testANewBuyerCommentMakesTheDraftStale(): void
    {
        $first = QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000');
        $serviced = QuoteSnapshotFixture::snapshot(comments: [$first]);
        $later = QuoteSnapshotFixture::snapshot(comments: [
            $first,
            QuoteSnapshotFixture::buyerComment('2026-09-23 11:00:00.000'),
        ]);

        self::assertNotSame(ServicingFingerprint::of($later), ServicingFingerprint::review($serviced, $serviced));
    }

    /** A comment that landed while the pass ran was never read by it. */
    public function testACommentThatLandedDuringThePassIsNotCredited(): void
    {
        $first = QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000');
        $serviced = QuoteSnapshotFixture::snapshot(comments: [$first]);
        $live = QuoteSnapshotFixture::snapshot(comments: [
            $first,
            QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:05.000'),
        ]);

        self::assertNotSame(ServicingFingerprint::of($live), ServicingFingerprint::review($serviced, $live));
    }

    public function testABuyerEditedAskMakesTheDraftStale(): void
    {
        $atDraft = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(9.0)]);
        $edited = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(8.0)]);

        self::assertNotSame(ServicingFingerprint::of($edited), ServicingFingerprint::review($atDraft, $atDraft));
    }
}
