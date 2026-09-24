<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

final class ReviewFingerprintTest extends TestCase
{
    /**
     * The reason review() exists: AskMirror writes the buyer's ask onto the
     * line DURING the pass, so of(live) right after a draft differs from the
     * handler's stamp. review() takes the asks from the live read, so a Send
     * straight after the draft is not stale.
     */
    public function testAMirroredAskDoesNotMakeTheDraftStale(): void
    {
        $comment = QuoteSnapshotFixture::buyerComment('2026-09-23 10:00:00.000');
        $serviced = QuoteSnapshotFixture::snapshot(comments: [$comment], lines: [QuoteSnapshotFixture::line(null)]);
        $mirrored = QuoteSnapshotFixture::snapshot(comments: [$comment], lines: [QuoteSnapshotFixture::line(9.0)]);

        self::assertSame(ServicingFingerprint::of($mirrored), ServicingFingerprint::review($serviced, $mirrored));
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
