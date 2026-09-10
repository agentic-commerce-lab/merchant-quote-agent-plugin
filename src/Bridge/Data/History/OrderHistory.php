<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * The aggregate over EVERY order, plus the newest few in full. One DAL search
 * produces both: aggregations run over the whole filtered set regardless of the
 * criteria's limit.
 */
final readonly class OrderHistory
{
    /** @param list<OrderHistoryEntry> $recent */
    public function __construct(
        public OrderStats $stats = new OrderStats(),
        public array $recent = [],
    ) {}
}
