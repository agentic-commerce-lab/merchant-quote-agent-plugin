<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Data;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

final readonly class ServiceQuoteMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $messageId,
        public string $quoteId,
        public string $salesChannelId,
        public QuoteRevision $revision,
    ) {}
}
