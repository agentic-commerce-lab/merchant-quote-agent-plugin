<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge\Commercial;

use MerchantQuoteAgentPlugin\Bridge\Commercial\SwagCommercialCommentWriter;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

/**
 * QuoteCommenter is @internal in SwagCommercial, so the writer is constructed
 * with a plain object standing in for it — $quoteCommenter is untyped for
 * exactly this reason. Real regression this pins: SwagCommercial's own admin
 * template renders every comment via
 * `commentVariant(item.stateMachineState.technicalName)` with no optional
 * chaining, so a comment written with a null state takes the WHOLE comment
 * list down for the merchant, not just this one row. Verified against a live
 * shop (see the design doc and the state-assoc report). The fake below
 * declares $state as required with no default, so a call that goes back to
 * omitting it is a TypeError, not a silent null — that is what makes this test
 * able to fail if the fix regresses.
 */
final class SwagCommercialCommentWriterTest extends TestCase
{
    public function testTheStateReachesQuoteCommentersFifthArgument(): void
    {
        $spy = new class {
            public ?string $seenState = 'not called';

            public function comment(
                Context $context,
                string $comment,
                string $quoteId,
                ?string $customerId,
                string $state,
            ): void {
                $this->seenState = $state;
            }
        };

        (new SwagCommercialCommentWriter($spy))->comment(
            'quote-1',
            'A reply to the buyer',
            Context::createDefaultContext(),
            'in_review',
        );

        self::assertSame('in_review', $spy->seenState);
    }

    public function testNoCustomerOrEmployeeIsAttributedToAnAgentComment(): void
    {
        $spy = new class {
            public ?string $seenCustomerId = 'not called';

            public function comment(
                Context $context,
                string $comment,
                string $quoteId,
                ?string $customerId,
                string $state,
            ): void {
                $this->seenCustomerId = $customerId;
            }
        };

        (new SwagCommercialCommentWriter($spy))->comment(
            'quote-1',
            'A reply to the buyer',
            Context::createDefaultContext(),
            'open',
        );

        self::assertNull($spy->seenCustomerId, 'An agent comment must not be attributed to a customer.');
    }
}
