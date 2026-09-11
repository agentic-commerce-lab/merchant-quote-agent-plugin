<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/**
 * What the records endpoints need to know about a quote's Shopware-side
 * state: its state machine name, whether it has expired, the human-facing
 * quote number, the sales channel it belongs to, and the acceptance act off
 * its chain, if any.
 *
 * `customFields` carries the same snapshot's raw custom fields alongside the
 * derived fields above — so a caller that also needs the act chain (the
 * inbound message route) can build it via `ActChain::read($this->customFields)`
 * off this one object. `for()` already parses the chain internally to find
 * `acceptance`, so this field is what lets a caller reuse that same read
 * instead of fetching the snapshot a second time.
 */
final readonly class QuoteTerminalState
{
    /**
     * @param array<string, mixed> $customFields
     *
     * @mago-expect lint:excessive-parameter-list
     * Promoted read-model fields are its interface; named arguments keep
     * callers explicit.
     */
    public function __construct(
        public string $state,
        public bool $expired,
        public string $quoteNumber,
        public string $salesChannelId,
        public ?Act $acceptance,
        public string $buyerOrganizationName = '',
        public ?string $orderNumber = null,
        public array $customFields = [],
    ) {}
}
