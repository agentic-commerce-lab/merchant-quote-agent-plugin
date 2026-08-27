<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\QuoteCommentWriterInterface;

/**
 * The gateway's two writes that carry no money and no revision precondition:
 * appending to the conversation and driving the state machine. Kept apart from
 * QuoteWriters because the seam is real rather than arithmetic — everything in
 * QuoteWriters changes what the quote costs and therefore needs recalculate()
 * and optimistic locking around it, while neither of these two touches a
 * price, and both are observable outside the shop (a comment the buyer reads,
 * a transition that fires mail flows).
 */
final readonly class QuoteLifecycleWriters
{
    public function __construct(
        public QuoteCommentWriterInterface $comments,
        public QuoteStateTransitioner $state,
    ) {}
}
