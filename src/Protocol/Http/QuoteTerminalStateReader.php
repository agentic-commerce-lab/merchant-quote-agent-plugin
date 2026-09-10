<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;

/**
 * The Shopware-side state a records request needs, read fresh every time —
 * never cached, the same policy as the records themselves.
 *
 * `for()` makes a deliberate three-way split rather than a nullable return:
 * the quote genuinely not existing (QuoteNotFoundException) is a 404, the
 * lookup itself failing for any other reason is a 502 — Shopware being
 * unreachable must never surface as a generic 500 on a records request. Null
 * means the former; QuoteStateUnavailable, thrown, means the latter.
 *
 * `$gateway` is nullable, defaulted, and last — matching SellerActEmitter and
 * ObserveQuoteHandler: the container's only definition of
 * QuoteGatewayInterface is QuoteGatewayFactory::create(), which returns null
 * when SwagCommercial's classes exist but the shop is unlicensed. A
 * non-nullable parameter here would make the container pass null into a typed
 * constructor argument and raise a TypeError the first time a records request
 * reached this class — which is exactly the generic-500 outcome this class
 * exists to avoid. A missing gateway is treated the same as a failed lookup:
 * both mean the state cannot be read, so both answer 502.
 *
 * Not `final`: the tests substitute it.
 */
class QuoteTerminalStateReader
{
    public function __construct(
        private readonly ?QuoteGatewayInterface $gateway = null,
    ) {}

    /** @throws QuoteStateUnavailable */
    public function for(string $quoteId, \DateTimeImmutable $now): ?QuoteTerminalState
    {
        if ($this->gateway === null) {
            throw new QuoteStateUnavailable(\sprintf('No quote gateway available to read quote "%s".', $quoteId));
        }

        try {
            $snapshot = $this->gateway->fetchSnapshot($quoteId);
        } catch (QuoteNotFoundException) {
            return null;
        } catch (\Throwable $error) {
            throw new QuoteStateUnavailable(
                \sprintf('Unable to read the state of quote "%s".', $quoteId),
                previous: $error,
            );
        }

        $chain = ActChain::read($snapshot->lifecycle->customFields);

        return new QuoteTerminalState(
            state: $snapshot->lifecycle->stateTechnicalName,
            expired: $snapshot->lifecycle->expiresAt !== null && $now >= $snapshot->lifecycle->expiresAt,
            quoteNumber: $snapshot->identity->quoteNumber,
            salesChannelId: $snapshot->identity->salesChannelId,
            acceptance: self::lastAcceptance($chain),
        );
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws QuoteStateUnavailable
     */
    public function customFieldsFor(string $quoteId): ?array
    {
        if ($this->gateway === null) {
            throw new QuoteStateUnavailable(\sprintf('No quote gateway available to read quote "%s".', $quoteId));
        }

        try {
            $snapshot = $this->gateway->fetchSnapshot($quoteId);
        } catch (QuoteNotFoundException) {
            return null;
        } catch (\Throwable $error) {
            throw new QuoteStateUnavailable(
                \sprintf('Unable to read the state of quote "%s".', $quoteId),
                previous: $error,
            );
        }

        return $snapshot->lifecycle->customFields;
    }

    private static function lastAcceptance(ActChain $chain): ?Act
    {
        $acceptance = null;
        foreach ($chain->acts() as $act) {
            if ($act->messageType() === 'acceptance') {
                $acceptance = $act;
            }
        }

        return $acceptance;
    }
}
