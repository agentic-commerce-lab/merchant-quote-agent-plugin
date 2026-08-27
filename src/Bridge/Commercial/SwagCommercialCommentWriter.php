<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\Context;

/**
 * @internal-dependency
 * Shopware\Commercial\B2B\QuoteManagement\Domain\Comment\QuoteCommenter::comment()
 * — @internal in SwagCommercial, and its $state parameter is
 * @deprecated tag:v6.8.0, so this call site needs revisiting for 6.8.
 */
final readonly class SwagCommercialCommentWriter implements QuoteCommentWriterInterface
{
    public function __construct(
        private object $quoteCommenter,
    ) {}

    #[\Override]
    public function comment(string $quoteId, string $comment, Context $context): void
    {
        // Signature: comment(Context, string $comment, string $quoteId, ...nullable).
        /** @mago-expect analysis:ambiguous-object-method-access */
        $this->quoteCommenter->comment($context, $comment, $quoteId);
    }
}
