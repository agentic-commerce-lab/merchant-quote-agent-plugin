<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * One past order with its lines. The lines are the point: an aggregate answers
 * "how much do they spend", only the lines answer "what do they buy" and "what
 * was in the deal they walked away from".
 */
final readonly class OrderHistoryEntry
{
    /**
     * @param list<OrderLineEntry> $lines
     * @mago-expect lint:excessive-parameter-list
     * Promoted read-model fields are its interface; named arguments keep callers explicit.
     */
    public function __construct(
        public string $orderNumber,
        public ?\DateTimeImmutable $orderedAt,
        public float $amountNet,
        public string $state,
        public array $lines = [],
        public ?string $currencyIso = null,
    ) {}
}
