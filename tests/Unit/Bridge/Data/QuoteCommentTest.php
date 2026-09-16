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

    /**
     * The third party. A merchant's note through the administration sets
     * `createdById` and nothing else — SwagCommercial's QuoteActionController
     * hard-codes customerId and employeeId to null and QuoteCommenter takes
     * createdById from the AdminApiSource. It is authored, because a person
     * wrote it, and it is not the buyer's, which is the whole of #55.
     */
    public function testAMerchantCommentIsAuthoredButNotTheBuyers(): void
    {
        $comment = new QuoteComment('check with sales before replying', createdById: 'user-1');

        self::assertTrue($comment->isAuthored());
        self::assertFalse($comment->isBuyerAuthored());
    }

    public function testACustomerCommentIsTheBuyers(): void
    {
        self::assertTrue((new QuoteComment('can you do better?', customerId: 'customer-1'))->isBuyerAuthored());
    }

    /** A B2B employee buying on the company's behalf is the buyer too. */
    public function testAnEmployeeCommentIsTheBuyers(): void
    {
        self::assertTrue((new QuoteComment('can you do better?', employeeId: 'employee-1'))->isBuyerAuthored());
    }

    public function testAnAgentCommentIsNeitherAuthoredNorTheBuyers(): void
    {
        $comment = new QuoteComment('here is our offer');

        self::assertFalse($comment->isAuthored());
        self::assertFalse($comment->isBuyerAuthored());
    }
}
