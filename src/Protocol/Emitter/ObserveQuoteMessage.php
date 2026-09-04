<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * "Look at this quote's act chain."
 *
 * Payload is the quote id and nothing that can go stale: the handler re-reads
 * the snapshot, which is a better source than a serialised copy.
 */
final readonly class ObserveQuoteMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $quoteId,
    ) {}
}
