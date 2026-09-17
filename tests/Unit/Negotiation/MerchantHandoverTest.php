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

/** @see docs/superpowers/specs/2026-09-16-merchant-handover-stand-down-design.md */
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
