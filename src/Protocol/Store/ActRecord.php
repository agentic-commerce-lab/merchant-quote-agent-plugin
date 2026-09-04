<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Store;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/** One row of the mirror. */
final readonly class ActRecord
{
    public function __construct(
        public string $sessionId,
        public string $quoteId,
        public int $sequence,
        public Act $act,
    ) {}
}
