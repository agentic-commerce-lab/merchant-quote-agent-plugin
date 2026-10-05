<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteCommentMapper;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\LegacyQuoteCommentEntity;
use MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Fixtures\ModernQuoteCommentEntity;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * `quote_comment.quote_line_item_id` is trunk-only. The comment itself is the
 * one thing a legacy shop must never lose, because there it is the buyer's only
 * ask channel — so the guard drops the scoping, not the text.
 *
 * The quote container may stay an `ArrayEntity` — `comments` is not a gated
 * field — but each comment is a real `Entity` subclass
 * (`Fixtures/LegacyQuoteCommentEntity` and `.../ModernQuoteCommentEntity`):
 * an `ArrayEntity` comment never throws on a missing key, so it could not
 * tell a passing guard from a deleted one.
 */
final class QuoteCommentMapperTest extends TestCase
{
    public function testAModernShopReadsTheLineScope(): void
    {
        $comments = (new QuoteCommentMapper(CommercialCapabilities::modern()))->map(self::quote([
            new ModernQuoteCommentEntity(quoteLineItemId: 'line-1'),
        ]));

        self::assertSame('line-1', $comments[0]->lineItemId);
    }

    public function testALegacyShopKeepsTheTextAndReportsNoLineScope(): void
    {
        // LegacyQuoteCommentEntity declares no `quoteLineItemId` property at
        // all: reading it would throw.
        $comments = (new QuoteCommentMapper(CommercialCapabilities::legacy()))->map(self::quote([
            new LegacyQuoteCommentEntity(),
        ]));

        self::assertCount(1, $comments);
        self::assertSame('Can you do better on price?', $comments[0]->comment);
        self::assertNull($comments[0]->lineItemId);
    }

    /**
     * QA-05: the newest administration comment is what PendingEscalation
     * weighs while the quote sits in `replied`. `created_by_id` is in the
     * table on both lanes, so the legacy fixture reads it exactly as the
     * modern one does.
     */
    public function testTheNewestMerchantCommentIsReadOnBothLanes(): void
    {
        foreach ([CommercialCapabilities::legacy(), CommercialCapabilities::modern()] as $capabilities) {
            $comments = (new QuoteCommentMapper($capabilities))->map(self::quote([
                self::comment($capabilities, 'user-1', null, '2026-10-01 09:00:00'),
                self::comment($capabilities, 'user-1', null, '2026-10-01 11:00:00'),
                // The buyer and the agent, both newer than either note: neither counts.
                self::comment($capabilities, null, 'customer-1', '2026-10-01 12:00:00'),
                self::comment($capabilities, null, null, '2026-10-01 13:00:00'),
            ]));

            self::assertEquals(
                new \DateTimeImmutable('2026-10-01 11:00:00'),
                QuoteCommentMapper::newestMerchantAt($comments),
            );
        }
    }

    public function testAQuoteNoMerchantCommentedOnHasNoMerchantTime(): void
    {
        $comments = (new QuoteCommentMapper(CommercialCapabilities::modern()))->map(self::quote([
            self::comment(CommercialCapabilities::modern(), null, 'customer-1', '2026-10-01 12:00:00'),
            self::comment(CommercialCapabilities::modern(), null, null, '2026-10-01 13:00:00'),
        ]));

        self::assertNull(QuoteCommentMapper::newestMerchantAt($comments));
        self::assertNull(QuoteCommentMapper::newestMerchantAt([]));
    }

    private static function comment(
        CommercialCapabilities $lane,
        ?string $createdById,
        ?string $customerId,
        string $at,
    ): Entity {
        $comment = $lane->lineScopedComments
            ? new ModernQuoteCommentEntity(createdById: $createdById, customerId: $customerId)
            : new LegacyQuoteCommentEntity(createdById: $createdById, customerId: $customerId);

        return $comment->assign(['createdAt' => new \DateTimeImmutable($at)]);
    }

    /** @param list<Entity> $comments */
    private static function quote(array $comments): Entity
    {
        return new ArrayEntity(['comments' => $comments]);
    }
}
