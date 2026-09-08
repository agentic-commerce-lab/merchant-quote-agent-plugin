<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteCommentMapper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayEntity;

/**
 * `quote_comment.quote_line_item_id` is trunk-only. The comment itself is the
 * one thing a legacy shop must never lose, because there it is the buyer's only
 * ask channel — so the guard drops the scoping, not the text.
 */
final class QuoteCommentMapperTest extends TestCase
{
    public function testAModernShopReadsTheLineScope(): void
    {
        $comments = (new QuoteCommentMapper(CommercialCapabilities::modern()))->map(self::quote([
            self::comment(['quoteLineItemId' => 'line-1']),
        ]));

        self::assertSame('line-1', $comments[0]->lineItemId);
    }

    public function testALegacyShopKeepsTheTextAndReportsNoLineScope(): void
    {
        $comments = (new QuoteCommentMapper(CommercialCapabilities::legacy()))->map(self::quote([
            self::comment([]),
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

    /** @param array<string, mixed> $extra */
    private static function comment(array $extra): Entity
    {
        return new ArrayEntity([
            'comment' => 'Can you do better on price?',
            'createdById' => null,
            'customerId' => 'customer-1',
            'createdAt' => null,
            'employeeId' => null,
            ...$extra,
        ]);
    }
}
