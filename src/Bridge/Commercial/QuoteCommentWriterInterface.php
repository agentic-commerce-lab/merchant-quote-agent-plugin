<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/**
 * Mirrors QuoteCommenter::comment(), which is @internal in SwagCommercial.
 *
 * $state is the quote's state technical name at the moment the comment is
 * written (e.g. 'in_review'), not a target state to transition to — see
 * SwagCommercialCommentWriter for why that distinction matters.
 */
interface QuoteCommentWriterInterface
{
    public function comment(string $quoteId, string $comment, Context $context, string $state): void;
}
