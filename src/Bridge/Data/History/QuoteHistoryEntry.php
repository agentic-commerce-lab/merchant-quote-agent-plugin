<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * One of the company's past quotes, joined with its latest recorded pass reduction.
 *
 * `grantedDiscountPercent` compares the latest recorded pass’s opening and
 * closing totals. It is not cumulative across passes or proof of delivery.
 * Null means no recorded reduction, even if an earlier proposal was authorized.
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
        public ?string $currencyIso = null,
    ) {}
}
