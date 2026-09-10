<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/**
 * What the records endpoints need to know about a quote's Shopware-side
 * state: its state machine name, whether it has expired, the human-facing
 * quote number, the sales channel it belongs to, and the acceptance act off
 * its chain, if any.
 */
final readonly class QuoteTerminalState
{
    /**
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
    ) {}
}
