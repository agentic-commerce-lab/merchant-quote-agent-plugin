<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Negotiation\MerchantHandover;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * @see docs/superpowers/specs/2026-09-16-merchant-handover-stand-down-design.md
 *
 * @mago-expect lint:too-many-methods
 * Fourteen cases plus four private helpers (single-line and multi-line quote
 * builders, a line builder, and the merchant-comment builder) covering both
 * the whole-quote comment path and the per-line comment-less ask path. Same
 * shape as the existing suppression on QuoteBaselineTest in this same
 * directory (fifteen cases plus six private helpers): count grows with
 * cases and their shared fixture builders, not with unrelated concerns.
 */
final class MerchantHandoverTest extends TestCase
{
    public function testAMerchantWhoAnsweredAfterTheBuyerHasTakenOver(): void
    {
        $snapshot = self::quote(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            self::merchantComment('2026-09-16 10:00:00'),
        ]);

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testABuyerWhoCameBackAfterTheMerchantReEnablesTheAgent(): void
    {
        $snapshot = self::quote(comments: [
            self::merchantComment('2026-09-16 10:00:00'),
            NegotiationFixture::buyerComment('can you do better?', '2026-09-16 11:00:00'),
        ]);

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testAnAdminTransitionCountsEvenWithNoMerchantComment(): void
    {
        $snapshot = self::quote(comments: [NegotiationFixture::buyerComment(
            '5% please',
            '2026-09-16 09:00:00',
        )], lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'));

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testAnAdminTransitionOlderThanTheBuyersAskDoesNotStandTheAgentDown(): void
    {
        $snapshot = self::quote(comments: [NegotiationFixture::buyerComment(
            '5% please',
            '2026-09-16 11:00:00',
        )], lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'));

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /**
     * A tie goes to the human, matching BuyerConversation::agentSpokeLast().
     * Two admin-request writes — a comment and a transition, say — can land
     * in the same millisecond, and `>` would have resolved that in the
     * agent's favour.
     */
    public function testATieBetweenTheMerchantsCommentAndTheBuyersCommentStandsTheAgentDown(): void
    {
        $snapshot = self::quote(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 10:00:00.500000'),
            self::merchantComment('2026-09-16 10:00:00.500000'),
        ]);

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /** The same tie, on the other dating path: an admin transition against a comment-less fresh ask. */
    public function testATieBetweenAnAdminTransitionAndAFreshAskStandsTheAgentDown(): void
    {
        $tied = new \DateTimeImmutable('2026-09-16 10:00:00.500000');
        $snapshot = self::quote(
            comments: [],
            lastAdminTransitionAt: $tied,
            requestedUnitPrice: 90.0,
            lineUpdatedAt: $tied,
            stampedAsks: '',
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /**
     * The ceiling the class docblock now names outright: `updatedAt` moves on
     * ANY write to the line, so a merchant's hand-edit of the line's unit
     * price — the column OfferApplier overwrites, not the buyer's own
     * `requested_price` — restamps a buyer ask that was already sitting
     * there unread. The buyer's real ask (T1, not modelled here since only
     * the line's OWN updatedAt is read) ends up dated by the merchant's
     * later write (T2b), which reads as newer than the merchant's own
     * comment (T2a) and the agent is not stood down. This is the accepted
     * gap, not a regression: fixing it needs per-write authorship the
     * `quote_line_item` row does not carry.
     */
    public function testAMerchantsUnrelatedLineEditMasksTheirOwnTakeover(): void
    {
        $snapshot = self::quote(
            comments: [self::merchantComment('2026-09-16 10:00:00')],
            requestedUnitPrice: 75.0,
            lineUpdatedAt: new \DateTimeImmutable('2026-09-16 10:05:00'),
            stampedAsks: 'line-1:80.00',
        );

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testAQuoteNoHumanMerchantHasEverTouchedIsServiced(): void
    {
        $snapshot = self::quote(comments: [NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00')]);

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /** The comment-less ask: the buyer edits their per-line target and types nothing. */
    public function testARequestedPriceChangedAfterTheMerchantActedIsANewBuyerAsk(): void
    {
        $snapshot = self::quote(
            comments: [],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            requestedUnitPrice: 90.0,
            lineUpdatedAt: new \DateTimeImmutable('2026-09-16 11:00:00'),
            stampedAsks: '',
        );

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    public function testARequestedPriceChangedBeforeTheMerchantActedIsTheirsToAnswer(): void
    {
        $snapshot = self::quote(
            comments: [],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 12:00:00'),
            requestedUnitPrice: 90.0,
            lineUpdatedAt: new \DateTimeImmutable('2026-09-16 11:00:00'),
            stampedAsks: '',
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /**
     * The line whose ask the last pass ALREADY saw is not new input, however
     * recently the line itself was written — our own price write moves
     * `updatedAt` on every line we concede on.
     */
    public function testALineWeAlreadyAnsweredIsNotReadAsAFreshAsk(): void
    {
        $snapshot = self::quote(
            comments: [],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            requestedUnitPrice: 90.0,
            lineUpdatedAt: new \DateTimeImmutable('2026-09-16 11:00:00'),
            stampedAsks: 'line-1:90.00',
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /**
     * Two lines, both stamped. The buyer changes line A's ask; line B is
     * untouched by the buyer but carries a NEWER `updatedAt` from the agent's
     * own price concession — its token still matches the stamp, so it must
     * not be read as buyer input. The merchant acted between the buyer's
     * change to line A and the agent's concession on line B.
     *
     * Against the unstamped-signature code this is a false negative: any
     * change anywhere makes it fall back to the newest `updatedAt` across
     * every line carrying an ask, and line B's concession timestamp then
     * outdates the merchant's own action.
     */
    public function testAnUnrelatedLinesOwnConcessionDoesNotMaskAMerchantTakeover(): void
    {
        $snapshot = self::multiLineQuote(
            lines: [
                self::line('line-a', 85.0, new \DateTimeImmutable('2026-09-16 09:00:00')),
                self::line('line-b', 90.0, new \DateTimeImmutable('2026-09-16 11:00:00')),
            ],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            stampedAsks: 'line-a:80.00,line-b:90.00',
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /**
     * The same shape, but the buyer's change to line A lands AFTER the
     * merchant's action: their fresh ask re-enables the agent regardless of
     * line B's untouched, later-concession timestamp.
     */
    public function testABuyersLaterChangeOnOneLineReEnablesTheAgentDespiteAnUnrelatedConcession(): void
    {
        $snapshot = self::multiLineQuote(
            lines: [
                self::line('line-a', 85.0, new \DateTimeImmutable('2026-09-16 11:00:00')),
                self::line('line-b', 90.0, new \DateTimeImmutable('2026-09-16 10:30:00')),
            ],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            stampedAsks: 'line-a:80.00,line-b:90.00',
        );

        self::assertFalse(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    /**
     * A multi-line quote never serviced before: no stamp at all, so every
     * line carrying an ask is fresh input, dated by its own `updatedAt`.
     */
    public function testAMultiLineQuoteNeverServicedDatesEveryAskByItsOwnLine(): void
    {
        $snapshot = self::multiLineQuote(
            lines: [
                self::line('line-a', 80.0, new \DateTimeImmutable('2026-09-16 09:00:00')),
                self::line('line-b', 90.0, new \DateTimeImmutable('2026-09-16 09:30:00')),
            ],
            lastAdminTransitionAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            stampedAsks: '',
        );

        self::assertTrue(MerchantHandover::tookOver($snapshot, SnapshotAdapter::conversation($snapshot)));
    }

    private static function line(
        string $id,
        ?float $requestedUnitPrice,
        ?\DateTimeImmutable $updatedAt,
    ): QuoteLineSnapshot {
        return new QuoteLineSnapshot(
            identity: new QuoteLineIdentity($id, 'Widget', 'prod-' . $id),
            quantity: 10,
            unitPriceNet: 100.0,
            totalNet: 1000.0,
            requestedUnitPrice: $requestedUnitPrice,
            updatedAt: $updatedAt,
        );
    }

    /** @param list<QuoteLineSnapshot> $lines */
    private static function multiLineQuote(
        array $lines,
        ?\DateTimeImmutable $lastAdminTransitionAt,
        string $stampedAsks,
    ): QuoteSnapshot {
        $base = NegotiationFixture::snapshot(comments: []);
        $customFields = $stampedAsks === '' ? [] : [ServicingFingerprint::MARKER_KEY => 'open|0|0|' . $stampedAsks];

        return new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: $base->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $base->lifecycle->stateTechnicalName,
                expiresAt: $base->lifecycle->expiresAt,
                customFields: $customFields,
                lastAdminTransitionAt: $lastAdminTransitionAt,
            ),
            content: new QuoteContent(lines: $lines, comments: []),
        );
    }

    private static function merchantComment(string $at): QuoteComment
    {
        return new QuoteComment('handled personally', createdById: 'admin-1', createdAt: new \DateTimeImmutable($at));
    }

    /** @param list<QuoteComment> $comments */
    private static function quote(
        array $comments = [],
        ?\DateTimeImmutable $lastAdminTransitionAt = null,
        ?float $requestedUnitPrice = null,
        ?\DateTimeImmutable $lineUpdatedAt = null,
        string $stampedAsks = '',
    ): QuoteSnapshot {
        $base = NegotiationFixture::snapshot(comments: $comments);
        $customFields = $stampedAsks === '' ? [] : [ServicingFingerprint::MARKER_KEY => 'open|0|0|' . $stampedAsks];

        return new QuoteSnapshot(
            identity: $base->identity,
            revision: $base->revision,
            totals: $base->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: $base->lifecycle->stateTechnicalName,
                expiresAt: $base->lifecycle->expiresAt,
                customFields: $customFields,
                lastAdminTransitionAt: $lastAdminTransitionAt,
            ),
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 10,
                unitPriceNet: 100.0,
                totalNet: 1000.0,
                requestedUnitPrice: $requestedUnitPrice,
                updatedAt: $lineUpdatedAt,
            )], comments: $comments),
        );
    }
}
