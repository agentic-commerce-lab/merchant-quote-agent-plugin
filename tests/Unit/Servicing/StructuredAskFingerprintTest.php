<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * The fingerprint's fourth component: the buyer's per-line requested prices.
 *
 * Its own class because ServicingFingerprintTest is at mago's method ceiling,
 * and because these four pin one decision each — that the component exists,
 * that it is append-only, what it is keyed on, and that line order is not part
 * of it.
 */
final class StructuredAskFingerprintTest extends TestCase
{
    /**
     * The gap this component closes. `requested_price` is the one ask that
     * arrives without a comment, so a buyer who edits it and types nothing
     * moves neither the state nor either comment component — and the handler
     * skipped the pass as "nothing has happened since the last one".
     */
    public function testAChangedStructuredAskChangesTheFingerprint(): void
    {
        $before = QuoteSnapshotFixture::snapshot('open', lines: [QuoteSnapshotFixture::line(9.80)]);
        $after = QuoteSnapshotFixture::snapshot('open', lines: [QuoteSnapshotFixture::line(9.70)]);

        self::assertNotSame(ServicingFingerprint::of($before), ServicingFingerprint::of($after));
    }

    /**
     * Every stamp already written omits this component, so a quote without a
     * structured ask must fingerprint to the string it fingerprinted before
     * the component existed. Otherwise deploying it re-services every quote
     * in the shop once, and the ones carrying an unanswered ask get answered
     * a second time.
     */
    public function testAQuoteWithoutAStructuredAskKeepsTheStampItAlreadyHas(): void
    {
        $snapshot = QuoteSnapshotFixture::snapshot('open', lines: [QuoteSnapshotFixture::line(null)]);

        self::assertSame('open|0|0', ServicingFingerprint::of($snapshot));
    }

    /**
     * Why the component lists every ask rather than only the unmet ones: the
     * agent's own write moves `unitPriceNet` down to meet the request, and a
     * met-only filter would drop the component at that moment, differing from
     * itself and buying one pointless pass per answered quote. The ask itself
     * is what the buyer controls, so the ask alone is what this reads.
     */
    public function testGrantingTheAskDoesNotChangeTheFingerprintComponent(): void
    {
        $asked = QuoteSnapshotFixture::snapshot('open', lines: [
            QuoteSnapshotFixture::line(9.80, unitPriceNet: 10.0),
        ]);
        $granted = QuoteSnapshotFixture::snapshot('open', lines: [
            QuoteSnapshotFixture::line(9.80, unitPriceNet: 9.80),
        ]);

        self::assertSame(ServicingFingerprint::of($asked), ServicingFingerprint::of($granted));
    }

    /** Line order is a read-model detail and must not read as new work. */
    public function testLineOrderDoesNotChangeTheFingerprint(): void
    {
        $one = QuoteSnapshotFixture::snapshot('open', lines: [
            QuoteSnapshotFixture::line(9.80, 'line-1'),
            QuoteSnapshotFixture::line(8.50, 'line-2'),
        ]);
        $other = QuoteSnapshotFixture::snapshot('open', lines: [
            QuoteSnapshotFixture::line(8.50, 'line-2'),
            QuoteSnapshotFixture::line(9.80, 'line-1'),
        ]);

        self::assertSame(ServicingFingerprint::of($one), ServicingFingerprint::of($other));
    }
}
