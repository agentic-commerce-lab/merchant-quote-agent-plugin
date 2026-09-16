<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * @mago-expect lint:too-many-methods
 * The fingerprint answers one question: has anything happened on this quote
 * that the agent has not already serviced? Deliberately NOT the quote's
 * revision — our own writes move `updatedAt`, so a revision marker would
 * differ from itself on the next pass and every duplicate trigger would look
 * like new work.
 */
final class ServicingFingerprintTest extends TestCase
{
    public function testIdenticalSnapshotsFingerprintIdentically(): void
    {
        $a = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100')]);
        $b = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100')]);

        self::assertSame(ServicingFingerprint::of($a), ServicingFingerprint::of($b));
    }

    /**
     * The property the whole design rests on. The agent's reply is author-less
     * (#3), so appending it must leave the fingerprint alone — otherwise the
     * agent's own write re-triggers servicing forever.
     */
    public function testAppendingAnAgentReplyDoesNotChangeTheFingerprint(): void
    {
        $before = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment(
            '2026-08-27 10:00:00.100',
        )]);
        $after = QuoteSnapshotFixture::snapshot('open', [
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
            new QuoteComment('agent reply', createdAt: new \DateTimeImmutable('2026-08-27 10:00:05.000')),
        ]);

        self::assertSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    /**
     * #55's second half. A merchant's note is not work the agent owes anyone,
     * so it must not read as "something new happened" — otherwise it buys a
     * pass, and that pass reads the note as the ask.
     *
     * This is the check that makes the fix hold even when the trigger cannot
     * tell who wrote a comment: the pass returns at ServiceQuoteHandler's
     * fingerprint comparison, before the preflight and before any model call.
     */
    public function testAppendingAMerchantCommentDoesNotChangeTheFingerprint(): void
    {
        $before = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment(
            '2026-08-27 10:00:00.100',
        )]);
        $after = QuoteSnapshotFixture::snapshot('open', [
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
            QuoteSnapshotFixture::merchantComment('2026-08-27 10:00:07.000'),
        ]);

        self::assertSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    public function testAppendingABuyerCommentChangesTheFingerprint(): void
    {
        $before = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment(
            '2026-08-27 10:00:00.100',
        )]);
        $after = QuoteSnapshotFixture::snapshot('open', [
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:09.200'),
        ]);

        self::assertNotSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    /**
     * A buyer comment that lands DURING a servicing pass, before the agent's
     * own reply, still changes the fingerprint — an interleaved agent reply
     * does not mask the buyer comment that preceded it.
     */
    public function testABuyerCommentOlderThanTheAgentReplyStillChangesTheFingerprint(): void
    {
        $before = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment(
            '2026-08-27 10:00:00.100',
        )]);
        $after = QuoteSnapshotFixture::snapshot('open', [
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:03.000'),
            new QuoteComment('agent reply', createdAt: new \DateTimeImmutable('2026-08-27 10:00:07.000')),
        ]);

        self::assertNotSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    /**
     * Two buyer comments can land in the same millisecond (the column is
     * datetime(3)), so the newest-authored-createdAt component alone cannot
     * tell them apart — the authored count is what catches this one.
     */
    public function testASecondAuthoredCommentInTheSameMillisecondChangesTheFingerprint(): void
    {
        $before = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment(
            '2026-08-27 10:00:00.100',
        )]);
        $after = QuoteSnapshotFixture::snapshot('open', [
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
        ]);

        self::assertNotSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    public function testAStateChangeChangesTheFingerprint(): void
    {
        $open = QuoteSnapshotFixture::snapshot('open', [QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100')]);
        $replied = QuoteSnapshotFixture::snapshot('replied', [QuoteSnapshotFixture::buyerComment(
            '2026-08-27 10:00:00.100',
        )]);

        self::assertNotSame(ServicingFingerprint::of($open), ServicingFingerprint::of($replied));
    }

    public function testAQuoteWithNoCommentsFingerprintsWithoutError(): void
    {
        self::assertSame('open|0|0', ServicingFingerprint::of(QuoteSnapshotFixture::snapshot('open', [])));
    }

    public function testAnAuthoredCommentWithNoTimestampDoesNotBreakTheMaximum(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot('open', [
            new QuoteComment('no timestamp', customerId: 'c1'),
            QuoteSnapshotFixture::buyerComment('2026-08-27 10:00:00.100'),
        ]);

        self::assertSame('open|2|1787824800.100000', ServicingFingerprint::of($snapshot));
    }

    public function testStampedReadsTheMarkerKeyAndNothingElse(): void
    {
        self::assertSame('open|1|123.000000', ServicingFingerprint::stamped([
            ServicingFingerprint::MARKER_KEY => 'open|1|123.000000',
            'unrelated' => 'value',
        ]));
    }

    public function testStampedIsNullWhenTheKeyIsAbsentOrNotAString(): void
    {
        self::assertNull(ServicingFingerprint::stamped([]));
        self::assertNull(ServicingFingerprint::stamped([ServicingFingerprint::MARKER_KEY => null]));
        self::assertNull(ServicingFingerprint::stamped([ServicingFingerprint::MARKER_KEY => 42]));
    }
}
