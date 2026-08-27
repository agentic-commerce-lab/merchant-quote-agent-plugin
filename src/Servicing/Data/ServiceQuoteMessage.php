<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Data;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * "This quote needs servicing." Implementing AsyncMessageInterface is all the
 * routing this needs: Shopware's framework.messenger already routes that
 * interface to the `async` transport, whose retry strategy is max_retries 3
 * with exponential backoff and `failed` as the failure transport.
 *
 * The payload is the quote id and nothing that can go stale. Not the revision:
 * the handler re-reads the snapshot anyway and the snapshot is a better source
 * than a serialised copy. Not the sales-channel id: it rides on
 * QuoteIdentity::$salesChannelId, which the handler hands to #18.
 *
 * `$reason` is the enum's VALUE rather than the enum, because the `async`
 * transport serializes through messenger.transport.symfony_serializer and a
 * two-string payload cannot fail to normalize. `because()` keeps the call site
 * typed.
 */
final readonly class ServiceQuoteMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $quoteId,
        public string $reason,
    ) {}

    public static function because(string $quoteId, ServicingTriggerReason $reason): self
    {
        return new self($quoteId, $reason->value);
    }
}
