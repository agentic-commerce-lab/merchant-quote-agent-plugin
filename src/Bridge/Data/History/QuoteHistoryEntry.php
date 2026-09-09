<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * One of the company's past quotes, joined with what WE granted on it.
 *
 * `grantedDiscountPercent` is null for a quote the agent never priced — a
 * merchant-handled quote, or one that predates this plugin. Null means "we
 * don't know", never "we gave nothing".
 */
final readonly class QuoteHistoryEntry
{
    /**
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and every
     * construction site uses named arguments, so the call-site complexity
     * this rule exists to catch does not arise here.
     */
    public function __construct(
        public string $quoteNumber,
        public ?\DateTimeImmutable $createdAt,
        public float $amountNet,
        public string $state,
        public bool $converted,
        public ?float $grantedDiscountPercent = null,
    ) {}
}
