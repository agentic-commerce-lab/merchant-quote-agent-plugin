<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/** Mirrors QuoteCommenter::comment(), which is @internal in SwagCommercial. */
interface QuoteCommentWriterInterface
{
    public function comment(string $quoteId, string $comment, Context $context): void;
}
