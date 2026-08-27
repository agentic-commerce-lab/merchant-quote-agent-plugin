<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteContent
{
    /**
     * @param list<QuoteLineSnapshot> $lines
     * @param list<QuoteComment> $comments
     */
    public function __construct(
        public array $lines = [],
        public array $comments = [],
    ) {}
}
