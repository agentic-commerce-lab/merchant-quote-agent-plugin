<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use Psr\Log\LoggerInterface;

/**
 * May this quote be serviced, and with what settings.
 *
 * Two off states, deliberately different. A kill switch the merchant threw is
 * silent — they paused the agent and do not want it talking. A missing API key
 * or config that fails its own constraints is a misconfiguration, and silence
 * there is the exact bug issue #5 exists to remove, so it escalates where the
 * merchant is already looking.
 *
 * Exists as one collaborator rather than two so ServiceQuoteHandler stays at
 * five constructor parameters.
 */
final readonly class ServicingPreflight
{
    public function __construct(
        private QuoteAgentSettingsSource $reader,
        private QuoteEscalator $escalator,
        private LoggerInterface $logger,
    ) {}

    /** Null means "do not service this quote"; the reason has already been handled. */
    public function check(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): ?QuoteAgentSettings
    {
        try {
            $settings = $this->reader->forSalesChannel($snapshot->identity->salesChannelId);
        } catch (InvalidQuoteAgentConfiguration $e) {
            $this->logger->error('The quote agent is enabled but its configuration is unusable, so this quote '
            . 'was escalated instead of serviced.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
                'problems' => $e->problems,
            ]);

            $this->escalator->escalate(
                $gateway,
                $snapshot,
                QuoteEscalationReason::NotConfigured,
                implode(' ', $e->problems),
            );

            return null;
        }

        if ($settings === null) {
            $this->logger->debug('The quote agent is switched off for this sales channel.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
            ]);
        }

        return $settings;
    }
}
