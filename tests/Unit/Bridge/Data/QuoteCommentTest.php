<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use PHPUnit\Framework\TestCase;

/**
 * `isAuthored()` is load-bearing for the servicing fingerprint: an agent
 * comment is author-less on all three fields (#3, pinned by AddCommentTest),
 * so "authored" is what separates a buyer's ask from our own reply.
 */
final class QuoteCommentTest extends TestCase
{
    public function testACommentWithNoAuthorFieldsIsNotAuthored(): void
    {
        $comment = new QuoteComment('agent reply');

        self::assertFalse($comment->isAuthored());
    }

    public function testACustomerCommentIsAuthored(): void
    {
        $comment = new QuoteComment('buyer ask', customerId: 'c1');

        self::assertTrue($comment->isAuthored());
    }

    public function testAnAdminAuthoredCommentIsAuthored(): void
    {
        $comment = new QuoteComment('staff note', createdById: 'u1');

        self::assertTrue($comment->isAuthored());
    }

    public function testAnEmployeeCommentIsAuthored(): void
    {
        $comment = new QuoteComment('employee ask', employeeId: 'e1');

        self::assertTrue($comment->isAuthored());
    }
}
