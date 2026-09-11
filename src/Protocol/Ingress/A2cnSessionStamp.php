<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Writes the quote's derived A2CN session id onto the quote, at the moment
 * the quote is created.
 *
 * SessionId::forQuote() is a one-way UUIDv5, so a session id on an inbound
 * act names a quote nobody can look up. This stamp is the index: it puts the
 * id where the Task 6 lookup can find it, and returns it to the buyer so they
 * need not reimplement the derivation.
 *
 * It does NOT open a session. SellerActEmitter gates on the chain being empty
 * of ACTS, not on the session key, so a stamped quote with no acts is still
 * inert and we still never emit first.
 *
 * Fail-open in both directions: a shop with no gateway (unlicensed) or a
 * write that throws leaves the snapshot untouched and the quote request
 * successful. Advertising an id we failed to persist would be worse than
 * advertising none — the buyer would sign an act for a session that cannot be
 * resolved.
 *
 * `$gateway` is nullable, defaulted and last, matching SellerActEmitter and
 * QuoteTerminalStateReader: QuoteGatewayFactory::create() returns null on a
 * shop where SwagCommercial's classes exist but the licence does not.
 */
final readonly class A2cnSessionStamp
{
    public function __construct(
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    public function stamp(QuoteSnapshot $snapshot): QuoteSnapshot
    {
        if ($this->gateway === null) {
            return $snapshot;
        }

        try {
            $sessionId = SessionId::forQuote($snapshot->id);
            $this->gateway->updateQuote($snapshot->id, new QuoteUpdate(customFields: [
                ActKey::SESSION_KEY => $sessionId,
            ]));

            return $snapshot->withA2cnSession($sessionId);
        } catch (\Throwable $error) {
            $this->logger->warning('A2CN could not stamp a session id onto a new quote.', [
                'quoteId' => $snapshot->id,
                'exception' => $error,
            ]);

            return $snapshot;
        }
    }
}
