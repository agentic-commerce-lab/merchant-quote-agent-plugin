<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;

/** Stands in for NegotiationPipeline: records the snapshot and gateway it was given, optionally writes a price, returns or throws. */
final class RecordingInnerPipeline implements QuoteServicingPipelineInterface
{
    public ?QuoteGatewayInterface $gateway = null;

    public ?QuoteSnapshot $snapshot = null;

    public function __construct(
        private readonly ?NegotiationOutcome $returns,
        private readonly bool $writesAPrice,
    ) {}

    #[\Override]
    public function service(
        QuoteSnapshot $snapshot,
        QuoteGatewayInterface $gateway,
        QuoteAgentSettings $settings,
        PassContext $context,
    ): NegotiationOutcome {
        $this->gateway = $gateway;
        $this->snapshot = $snapshot;

        if ($this->writesAPrice) {
            $gateway->updateQuote('q1', new QuoteUpdate(discount: new Discount(DiscountType::Percentage, 5.0)));
        }

        return $this->returns ?? throw new \RuntimeException('inner failed');
    }
}
