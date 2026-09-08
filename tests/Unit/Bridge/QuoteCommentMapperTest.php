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

    /** @param list<Entity> $comments */
    private static function quote(array $comments): Entity
    {
        return new ArrayEntity(['comments' => $comments]);
    }
}
