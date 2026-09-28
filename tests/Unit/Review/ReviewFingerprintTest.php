<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Review\ReviewFingerprint;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\QuoteSnapshotFixture;
use PHPUnit\Framework\TestCase;

final class ReviewFingerprintTest extends TestCase
{
    public function testOffsettingLinePriceEditsStillMakeTheDraftStale(): void
    {
        $before = QuoteSnapshotFixture::snapshot(lines: [
            QuoteSnapshotFixture::line(null, 'line-1', 10.0),
            QuoteSnapshotFixture::line(null, 'line-2', 10.0),
        ]);
        $after = QuoteSnapshotFixture::snapshot(lines: [
            QuoteSnapshotFixture::line(null, 'line-1', 9.0),
            QuoteSnapshotFixture::line(null, 'line-2', 11.0),
        ]);

        self::assertSame($before->totals->totalNet, $after->totals->totalNet);
        self::assertNotSame(ReviewFingerprint::atDraft($before, $before), ReviewFingerprint::current($after));
    }

    public function testLineOrderAndBookkeepingWritesDoNotMakeTheDraftStale(): void
    {
        $lineOne = QuoteSnapshotFixture::line(null, 'line-1');
        $lineTwo = QuoteSnapshotFixture::line(null, 'line-2');
        $before = QuoteSnapshotFixture::snapshot(lines: [$lineOne, $lineTwo]);
        $after = QuoteSnapshotFixture::snapshot(lines: [$lineTwo, $lineOne], customFields: [
            'merchant_quote_agent_attempts' => 1,
        ]);

        self::assertSame(ReviewFingerprint::atDraft($before, $before), ReviewFingerprint::current($after));
    }

    public function testGrossLineTaxSpaceChangeWithSameNetTotalsMakesTheDraftStale(): void
    {
        $before = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null)]);
        $line = $before->content->lines[0];
        $changed = new QuoteSnapshot(
            $before->identity,
            $before->revision,
            $before->totals,
            $before->lifecycle,
            new QuoteContent(lines: [new QuoteLineSnapshot(
                $line->identity,
                $line->quantity,
                $line->unitPriceNet,
                $line->totalNet,
                netRatio: 0.8,
            )]),
        );

        self::assertNotSame(ReviewFingerprint::atDraft($before, $before), ReviewFingerprint::current($changed));
    }

    public function testOffsettingCentLevelGrossEditsCannotHideBehindRoundedRatios(): void
    {
        $net = 100000.03;
        $before = QuoteSnapshotFixture::snapshot(lines: [
            self::grossLine('line-1', $net, 119000.03),
            self::grossLine('line-2', $net, 119000.04),
        ]);
        $after = QuoteSnapshotFixture::snapshot(lines: [
            self::grossLine('line-1', $net, 119000.04),
            self::grossLine('line-2', $net, 119000.03),
        ]);

        self::assertEquals($before->totals, $after->totals);
        self::assertNotSame(ReviewFingerprint::atDraft($before, $before), ReviewFingerprint::current($after));
    }

    public function testBuyerAskArrivingAfterThePassIsNotCreditedAsServiced(): void
    {
        $serviced = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null)]);
        $later = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(8.0)]);

        self::assertNotSame(ReviewFingerprint::atDraft($serviced, $later), ReviewFingerprint::current($later));
    }

    public function testBuyerVisibleLineLabelEditMakesTheDraftStale(): void
    {
        $before = QuoteSnapshotFixture::snapshot(lines: [QuoteSnapshotFixture::line(null)]);
        $line = $before->content->lines[0];
        $changed = new QuoteSnapshot(
            $before->identity,
            $before->revision,
            $before->totals,
            $before->lifecycle,
            new QuoteContent(lines: [new QuoteLineSnapshot(
                new QuoteLineIdentity($line->identity->lineItemId, 'Renamed product', $line->identity->productId),
                $line->quantity,
                $line->unitPriceNet,
                $line->totalNet,
            )]),
        );

        self::assertNotSame(ReviewFingerprint::atDraft($before, $before), ReviewFingerprint::current($changed));
    }

    private static function grossLine(string $id, float $net, float $gross): QuoteLineSnapshot
    {
        return new QuoteLineSnapshot(
            new QuoteLineIdentity($id),
            1,
            $net,
            $net,
            netRatio: $net / $gross,
            totalInQuotePriceSpace: $gross,
        );
    }
}
