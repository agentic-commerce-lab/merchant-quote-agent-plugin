<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Identity\SalesChannelCurrencyReader;
use MerchantQuoteAgentPlugin\Protocol\Mandate\MandateSigner;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Assembles and signs the seller mandate for one sales channel — split out of
 * A2cnDiscoveryController, whose own job is the three routes and identity
 * resolution shared across all of them.
 *
 * Neither `QuoteAgentSettingsSource::forSalesChannel()` returning null (the
 * agent is disabled for this channel) nor it throwing
 * InvalidQuoteAgentConfiguration (the channel's configuration does not pass
 * validation) leaves anything publishable: both degrade to the same "not
 * found" a buyer sees for any other mandate this installation cannot serve,
 * rather than surfacing the shop's own configuration state to an
 * unauthenticated fetcher.
 *
 * `MandateSigner::sign()` reads `A2cnKeyStore::current()` independently of
 * whatever `A2cnDiscoveryController::resolveIdentity()` already did — the
 * 503-on-missing-key guarantee must not rest on "identity resolution happens
 * to run first and hits the same key store", an implicit coupling that would
 * silently break if that call order ever changed. Caught here too, so the
 * mandate route answers 503 regardless of which call discovers the key is
 * missing.
 *
 * The mandate is served with a cache header, so a Draft Mode switch reaches
 * buyer agents when their cached copy expires.
 */
final readonly class MandateDocumentResponder
{
    public function __construct(
        private QuoteAgentSettingsSource $settings,
        private SellerMandateFactory $mandateFactory,
        private MandateSigner $signer,
        private SalesChannelCurrencyReader $currencies,
    ) {}

    public function respond(A2cnIdentity $identity, ?string $salesChannelId, \DateTimeImmutable $now): JsonResponse
    {
        try {
            $settings = $this->settings->forSalesChannel($salesChannelId);
        } catch (InvalidQuoteAgentConfiguration) {
            return JsonEnvelope::noStore(['status' => 'not_found'], 404);
        }

        if ($settings === null) {
            return JsonEnvelope::noStore(['status' => 'not_found'], 404);
        }

        // The storefront's own currency: what turns a bare "50000, whatever
        // the currency" ceiling into the scalar pair A2CN's mandate needs.
        $mandate = $this->mandateFactory->build(
            $settings->policy,
            $identity,
            $now,
            $this->currencies->isoFor($salesChannelId),
            $settings->draftMode,
        );

        try {
            $signed = $this->signer->sign($mandate, $identity, $now);
        } catch (MissingSigningKey) {
            return JsonEnvelope::noStore(['status' => 'signing_key_missing'], 503);
        }

        return JsonEnvelope::cached($signed);
    }
}
