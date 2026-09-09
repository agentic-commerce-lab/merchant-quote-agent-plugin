<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/**
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter::comment()
 * — @internal in SwagCommercial, and its $state parameter is
 * @deprecated tag:v6.8.0, so this call site needs revisiting for 6.8.
 *
 * $state is passed because QuoteCommenter resolves it into `quote_comment.state_id`
 * (identical on 6.7.12 and trunk for this argument), and SwagCommercial's own admin
 * template renders each comment via `commentVariant(item.stateMachineState.technicalName)`
 * with NO optional chaining — a comment whose state is null throws inside the Vue
 * render and takes the whole comment list down for the merchant, not just this one
 * comment. Every one of SwagCommercial's own callers (QuoteCommentRoute::sendMessage,
 * the admin sw-quote-history-sidebar's sendMessage) stamps a comment with the quote's
 * CURRENT state at write time, not a state it is about to transition to, so this
 * mirrors that rather than inventing a new convention.
 */
final readonly class SwagCommercialCommentWriter implements QuoteCommentWriterInterface
{
    public function __construct(
        private object $quoteCommenter,
    ) {}

    #[\Override]
    public function comment(string $quoteId, string $comment, Context $context, string $state): void
    {
        // Signature: comment(Context, string $comment, string $quoteId, ?customerId, ?state, ...).
        /** @mago-expect analysis:ambiguous-object-method-access */
        $this->quoteCommenter->comment($context, $comment, $quoteId, null, $state);
    }
}
