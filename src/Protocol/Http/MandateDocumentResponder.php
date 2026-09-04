<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
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
 */
final readonly class MandateDocumentResponder
{
    public function __construct(
        private QuoteAgentSettingsSource $settings,
        private SellerMandateFactory $mandateFactory,
        private MandateSigner $signer,
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

        $mandate = $this->mandateFactory->build($settings->policy, $identity, $now);

        return JsonEnvelope::cached($this->signer->sign($mandate, $identity, $now));
    }
}
