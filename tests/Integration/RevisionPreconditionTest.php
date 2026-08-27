<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteRevisionMismatch;
use Shopware\Core\Framework\Context;

/**
 * What the optimistic-concurrency precondition actually guarantees, measured
 * rather than assumed — the spec's Risks section left it open.
 *
 * The answer: it detects a quote that moved BEFORE the call and refuses the
 * write, at millisecond fidelity, and the refusal is total (nothing is
 * written). It is not a compare-and-set. `assertRevision()` re-reads and
 * compares, then the write follows as a separate statement with no
 * transaction, no `SELECT … FOR UPDATE` and no conditional `UPDATE`, so a
 * writer that commits inside that gap is not caught. That residual window is
 * a property of the code's structure, not something these tests can measure:
 * a second writer would need a second connection, and everything here runs
 * inside DatabaseTransactionBehaviour's uncommitted transaction where a
 * second connection sees nothing. The servicing lock (`symfony/lock`, one per
 * quote id, per the parent design) is what serialises writers; this
 * precondition is what stops a caller acting on a snapshot it read too long
 * ago.
 *
 * Only `versionId` and `updatedAt` are compared, and of those only
 * `updatedAt` can move here: `QuoteSnapshotReader::readRevision()` takes
 * `versionId` from the requested context, so it identifies the lane
 * (live/snapshot) rather than a per-quote version, and reading the same lane
 * twice always yields the same value.
 */
final class RevisionPreconditionTest extends IntegrationTestCase
{
    public function testAFreshRevisionIsAccepted(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $revision = $gateway->fetchSnapshot($quoteId)->revision;
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_fresh' => true]), $revision);

        self::assertTrue(
            $gateway->fetchSnapshot($quoteId)->lifecycle->customFields['probe_fresh'] ?? false,
            'A write carrying the revision it had just read was refused.',
        );
    }

    /**
     * The load-bearing half. Two assertions, in order: the intervening write
     * really did move the revision (without which the refusal below would
     * prove nothing), and the stale write is then refused.
     */
    public function testAStaleRevisionIsRefused(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $stale = $gateway->fetchSnapshot($quoteId)->revision;

        // Somebody else moves the quote, which is what makes $stale stale.
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_mover' => true]));

        self::assertFalse(
            $gateway->fetchSnapshot($quoteId)->revision->matches($stale),
            'A customFields write did not move the quote revision, so this quote cannot detect '
            . 'concurrent writes at all and the refusal below would be vacuous.',
        );

        $this->expectException(QuoteRevisionMismatch::class);
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_stale' => true]), $stale);
    }

    /**
     * The refusal has to be total, not partial: a mismatch detected after some
     * of the payload had landed would leave the quote in a state neither party
     * agreed to. Checked separately from the expectException test above, which
     * cannot assert anything after the throw.
     */
    public function testARefusedWriteChangesNothing(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $stale = $gateway->fetchSnapshot($quoteId)->revision;
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_mover' => true]));
        $before = $gateway->fetchSnapshot($quoteId);

        try {
            $gateway->updateQuote(
                $quoteId,
                new QuoteUpdate(expiresAt: new \DateTimeImmutable('+99 days'), customFields: ['probe_stale' => true]),
                $stale,
            );
            self::fail('The stale write was accepted.');
        } catch (QuoteRevisionMismatch $mismatch) {
            // Which quote lost matters to a caller servicing several at once.
            self::assertStringContainsString($quoteId, $mismatch->getMessage());
        }

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertArrayNotHasKey('probe_stale', $after->lifecycle->customFields);
        self::assertSame($before->lifecycle->expiresAt?->format('U.u'), $after->lifecycle->expiresAt?->format('U.u'));
        self::assertTrue($before->revision->matches($after->revision), 'The refused write still bumped updatedAt.');
    }

    /**
     * `updateLineItems` shares `assertRevision()` with `updateQuote`, but it
     * calls it itself, so a refactor could drop the call from one and not the
     * other. Cheap enough to pin both — and this is the money path.
     */
    public function testUpdateLineItemsRefusesAStaleRevision(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $stale = $gateway->fetchSnapshot($quoteId)->revision;
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_mover' => true]));

        $line = $gateway->fetchSnapshot($quoteId)->content->lines[0] ?? null;
        self::assertNotNull($line, 'The fixture quote has no line to reprice.');

        $this->expectException(QuoteRevisionMismatch::class);
        $gateway->updateLineItems(
            $quoteId,
            [new QuoteLineItemChange(lineItemId: $line->identity->lineItemId, unitPriceNet: 1.0)],
            $stale,
        );
    }

    /**
     * Millisecond fidelity, asserted end to end against a revision the reader
     * actually produced rather than in a unit test over two hand-built value
     * objects: `quote.updated_at` is `datetime(3)`, and a same-second
     * concurrent write is the likeliest collision, not the rarest. Shifting by
     * one millisecond is the smallest difference the column can hold.
     */
    public function testARevisionOneMillisecondOffIsRefused(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());

        $current = $gateway->fetchSnapshot($quoteId)->revision;
        $offByAMillisecond = new QuoteRevision(
            versionId: $current->versionId,
            updatedAt: $current->updatedAt->modify('-1 millisecond'),
        );

        self::assertNotSame(
            $current->updatedAt->format('U.u'),
            $offByAMillisecond->updatedAt->format('U.u'),
            'The shifted revision is identical, so this test would pass for the wrong reason.',
        );

        $this->expectException(QuoteRevisionMismatch::class);
        $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: ['probe_ms' => true]), $offByAMillisecond);
    }
}
