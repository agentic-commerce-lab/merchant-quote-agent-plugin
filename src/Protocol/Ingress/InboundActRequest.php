<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;

/**
 * Parameter object for InboundActAppender::append().
 *
 * Exists because the append needs all seven fields and the per-method
 * parameter cap is five; a parameter object is the repo's answer to that,
 * not a signature that busts the gate.
 *
 * @mago-expect lint:excessive-parameter-list
 * A parameter object's promoted properties are its interface; grouping them
 * here is what keeps InboundActAppender::append() within the parameter gate.
 *
 * @param Act $act The inbound act to append.
 * @param ActChain $chain The existing act chain read from the quote.
 * @param string $quoteId The Shopware quote identifier.
 * @param string $sessionId The session identifier derived from the quote.
 * @param QuoteTerminalState $quote The quote's Shopware terminal and outcome state.
 * @param string $sellerDid The merchant's own DID.
 * @param string $issuerDid The authenticated sender's DID from the bearer token.
 */
final readonly class InboundActRequest
{
    public function __construct(
        public Act $act,
        public ActChain $chain,
        public string $quoteId,
        public string $sessionId,
        public QuoteTerminalState $quote,
        public string $sellerDid,
        public string $issuerDid,
    ) {}
}
