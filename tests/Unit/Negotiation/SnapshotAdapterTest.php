<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use PHPUnit\Framework\TestCase;

/**
 * @mago-expect lint:too-many-methods
 * One test class per adapter method reads naturally; splitting it by method
 * would scatter conversation()'s three-way split across files for no reader's
 * benefit.
 */
final class SnapshotAdapterTest extends TestCase
{
    /** @param list<QuoteComment> $comments */
    private static function bridgeSnapshot(array $comments = [], ?float $requestedUnitPrice = null): QuoteSnapshot
    {
        return new QuoteSnapshot(
            identity: new QuoteIdentity('q1', '10001', 'EUR', 'sc1'),
            revision: new QuoteRevision('v1', new \DateTimeImmutable('2026-08-28 10:00:00.000')),
            totals: new QuoteTotals(totalNet: 250.0),
            lifecycle: new QuoteLifecycle(stateTechnicalName: 'open'),
            content: new QuoteContent(lines: [new QuoteLineSnapshot(
                identity: new QuoteLineIdentity('line-1', 'Widget', 'prod-1'),
                quantity: 5,
                unitPriceNet: 50.0,
                totalNet: 250.0,
                requestedUnitPrice: $requestedUnitPrice,
            )], comments: $comments),
        );
    }

    private static function buyer(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, customerId: 'cust-1', createdAt: new \DateTimeImmutable($at));
    }

    private static function agent(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, createdAt: new \DateTimeImmutable($at));
    }

    /** A merchant's note through the administration: createdById, nothing else. */
    private static function merchant(string $text, string $at): QuoteComment
    {
        return new QuoteComment($text, createdById: 'user-1', createdAt: new \DateTimeImmutable($at));
    }

    public function testItMapsTotalsCurrencyStateAndLines(): void
    {
        $policy = SnapshotAdapter::toPolicy(self::bridgeSnapshot());

        self::assertSame('EUR', $policy->currencyIso);
        self::assertSame(250.0, $policy->totalNet);
        self::assertSame('open', $policy->lifecycle->stateTechnicalName);
        self::assertCount(1, $policy->lines);
        self::assertSame('line-1', $policy->lines[0]->identity->lineItemId);
        self::assertSame('Widget', $policy->lines[0]->identity->label);
        self::assertSame(5, $policy->lines[0]->quantity);
        self::assertSame(50.0, $policy->lines[0]->unitPriceNet);
    }

    public function testALineLevelTargetPriceSurvivesTheMapping(): void
    {
        // The one structured ask that reaches the deciders without any comment.
        $policy = SnapshotAdapter::toPolicy(self::bridgeSnapshot(requestedUnitPrice: 45.0));

        self::assertSame(45.0, $policy->lines[0]->requestedUnitPrice);
    }

    public function testCommentsSplitByAuthorship(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::buyer('can you do better?', '2026-08-28 09:00:00'),
            self::agent('here is our offer', '2026-08-28 09:30:00'),
        ]));

        self::assertCount(1, $conversation->buyer);
        self::assertCount(1, $conversation->agent);
        self::assertSame('can you do better?', $conversation->buyer[0]->comment);
    }

    public function testABuyerCommentNewerThanTheAgentsReplyIsANewAsk(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::agent('here is our offer', '2026-08-28 09:00:00'),
            self::buyer('still too expensive', '2026-08-28 09:30:00'),
        ]));

        self::assertTrue($conversation->hasNewBuyerAsk());
        self::assertSame('still too expensive', $conversation->newestBuyerText());
        self::assertSame('still too expensive', $conversation->unansweredBuyerText());
    }

    public function testAnAgentReplyNewerThanEveryBuyerCommentIsNotANewAsk(): void
    {
        // The re-trigger case: nothing has happened since we answered, so the
        // extract call must be skipped entirely.
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::buyer('can you do better?', '2026-08-28 09:00:00'),
            self::agent('here is our offer', '2026-08-28 09:30:00'),
        ]));

        self::assertFalse($conversation->hasNewBuyerAsk());
        // Answered already: a reply on a later storefront round must not
        // answer it again.
        self::assertSame('can you do better?', $conversation->newestBuyerText());
        self::assertSame('', $conversation->unansweredBuyerText());
    }

    public function testAQuoteWithNoCommentsAtAllHasNoAsk(): void
    {
        self::assertFalse(SnapshotAdapter::conversation(self::bridgeSnapshot())->hasNewBuyerAsk());
    }

    /**
     * #55: a merchant's internal note is not the buyer talking. It must not
     * reach the buyer bucket, where it would become the ask a pass answers in
     * the thread the customer reads — SwagCommercial quote comments have no
     * private half.
     */
    public function testAMerchantCommentIsInNeitherBucket(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::merchant('customer wants 10%, check with sales', '2026-08-28 09:00:00'),
        ]));

        self::assertSame([], $conversation->buyer);
        self::assertSame([], $conversation->agent);
    }

    public function testAMerchantCommentIsNotANewAsk(): void
    {
        $conversation = SnapshotAdapter::conversation(self::bridgeSnapshot([
            self::buyer('can you do better?', '2026-08-28 09:00:00'),
            self::agent('here is our offer', '2026-08-28 09:30:00'),
            self::merchant('margin is thin on this one', '2026-08-28 10:00:00'),
        ]));

        self::assertFalse($conversation->hasNewBuyerAsk());
        self::assertSame('can you do better?', $conversation->newestBuyerText());
    }

    public function testAMerchantsNoteIsKeptSeparatelyAndNotAsTheBuyersAsk(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment(
                'called them, handling personally',
                createdById: 'admin-1',
                createdAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            ),
        ]));

        self::assertCount(1, $conversation->buyer, "A merchant's note is not the buyer's ask.");
        self::assertCount(0, $conversation->agent, "A merchant's note is not the agent's reply.");
        self::assertSame(
            (new \DateTimeImmutable('2026-09-16 10:00:00'))->format('U.u'),
            $conversation->merchantSpokeAt(),
        );
    }

    public function testTheMerchantBucketNeverLeaksIntoEitherPrompt(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            new QuoteComment(
                'internal: margin is thin',
                createdById: 'admin-1',
                createdAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            ),
        ]));

        self::assertSame('', $conversation->agentText());
        self::assertSame('5% please', $conversation->newestBuyerText());
    }

    public function testAnAgentReplyNewerThanEveryHumanIsAStrandedReply(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::buyerComment('5% please', '2026-09-16 09:00:00'),
            NegotiationFixture::agentComment('here is 5%', '2026-09-16 09:30:00'),
        ]));

        self::assertTrue($conversation->agentSpokeLast());
    }

    public function testAMerchantWritingAfterTheAgentIsNotAStrandedReply(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::agentComment('here is 5%', '2026-09-16 09:30:00'),
            new QuoteComment(
                'I took this one over',
                createdById: 'admin-1',
                createdAt: new \DateTimeImmutable('2026-09-16 10:00:00'),
            ),
        ]));

        self::assertFalse($conversation->agentSpokeLast());
    }

    public function testAQuoteWithNoCommentsAtAllHasNoStrandedReply(): void
    {
        self::assertFalse(SnapshotAdapter::conversation(NegotiationFixture::snapshot())->agentSpokeLast());
    }

    /**
     * A buyer comment at the exact same instant as the agent's is not older
     * than it, so a strict "newer than the agent" check would wrongly call
     * this a stranded reply. Ties go to the human.
     */
    public function testABuyerCommentAtTheSameInstantAsTheAgentIsNotAStrandedReply(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::agentComment('here is 5%', '2026-09-16 09:30:00'),
            NegotiationFixture::buyerComment('still 5%?', '2026-09-16 09:30:00'),
        ]));

        self::assertFalse($conversation->agentSpokeLast());
    }

    /** Same tie, on the merchant bucket instead of the buyer's. */
    public function testAMerchantCommentAtTheSameInstantAsTheAgentIsNotAStrandedReply(): void
    {
        $conversation = SnapshotAdapter::conversation(NegotiationFixture::snapshot(comments: [
            NegotiationFixture::agentComment('here is 5%', '2026-09-16 09:30:00'),
            new QuoteComment(
                'took over right as it replied',
                createdById: 'admin-1',
                createdAt: new \DateTimeImmutable('2026-09-16 09:30:00'),
            ),
        ]));

        self::assertFalse($conversation->agentSpokeLast());
    }
}
