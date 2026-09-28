<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use Shopware\Core\Framework\Context;

/**
 * Stage two of ADR 0001's gate: the license toggle decides whether the gateway
 * can serve. Returns null when it cannot, so callers degrade rather than fail —
 * the quote capability simply is not advertised (issue #1 owns that).
 *
 * Stage one, the quote bundle, is NOT here. It has to run at container-build
 * time, because by the time this class is constructible its collaborators
 * already hold SwagCommercial services in non-nullable `object` parameters —
 * so `services.php` guards the whole registration on
 * `CommercialAvailability::isRegistered()` and nothing below reaches this
 * point on a shop without SwagCommercial. `isLicensed()` re-checks class
 * existence anyway, which makes this correct standalone (the integration
 * harness constructs it by hand) rather than dependent on the caller.
 */
final readonly class QuoteGatewayFactory implements ContextBoundGateways
{
    public function __construct(
        private QuoteSnapshotReader $reader,
        private QuoteWriters $writers,
        private QuoteLifecycleWriters $lifecycle,
    ) {}

    public function create(): ?QuoteGatewayInterface
    {
        if (!CommercialAvailability::isLicensed()) {
            return null;
        }

        return new SwagCommercialQuoteGateway($this->reader, $this->writers, $this->lifecycle);
    }

    /**
     * A gateway whose reads and writes run under $context instead of the
     * agent's own live one: a Draft Mode version (AgentContext::forVersion())
     * or the admin user sending a draft. Null exactly when create() is.
     */
    #[\Override]
    public function forContext(Context $context): ?QuoteGatewayInterface
    {
        if (!CommercialAvailability::isLicensed()) {
            return null;
        }

        return new SwagCommercialQuoteGateway($this->reader, $this->writers, $this->lifecycle, $context);
    }
}
