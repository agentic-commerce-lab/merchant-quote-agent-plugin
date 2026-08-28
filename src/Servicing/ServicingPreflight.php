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
 * there is the exact bug issue #5 exists to remove, so it escalates: an error
 * log line for the merchant, carrying the problems, and a neutral comment for
 * the buyer. The two are deliberately not the same text — see QuoteEscalator.
 *
 * A quote in a state SwagCommercial refuses to edit is a third off state, and
 * the quietest of the three: nothing is wrong, there is simply nothing to do.
 *
 * Exists as one collaborator rather than two so ServiceQuoteHandler stays at
 * five constructor parameters.
 */
final readonly class ServicingPreflight
{
    /**
     * The states SwagCommercial itself refuses to edit
     * (QuoteSnapshotVersionResolver::NON_EDITABLE_STATES). A comment can still
     * be written against a quote in one of them — an accepted quote is a
     * conversation, not a closed file — and the trigger has no state filter on
     * the comment path, so without this the pipeline would be handed a quote
     * whose every write is going to be refused.
     *
     * Mirrored rather than read from SwagCommercial: ADR 0001 keeps untyped
     * commercial access in the bridge, and these four are a stable part of the
     * quote state machine. Sourced, not guessed — if it ever diverges, the
     * writes fail loudly rather than silently doing the wrong thing.
     */
    private const TERMINAL_STATES = ['accepted', 'declined', 'expired', 'cancelled'];

    public function __construct(
        private QuoteAgentSettingsSource $reader,
        private QuoteEscalator $escalator,
        private LoggerInterface $logger,
    ) {}

    /** Null means "do not service this quote"; the reason has already been handled. */
    public function check(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): ?QuoteAgentSettings
    {
        $state = $snapshot->lifecycle->stateTechnicalName;

        // First, so a terminal quote in a misconfigured shop is not escalated:
        // there is nothing to service there whatever the configuration says.
        if (\in_array($state, self::TERMINAL_STATES, strict: true)) {
            $this->logger->info('Quote is in a state SwagCommercial will not edit, so there is nothing to service.', [
                'quoteId' => $snapshot->identity->quoteId,
                'state' => $state,
            ]);

            return null;
        }

        try {
            $settings = $this->reader->forSalesChannel($snapshot->identity->salesChannelId);
        } catch (InvalidQuoteAgentConfiguration $e) {
            $this->logger->error('The quote agent is enabled but its configuration is unusable, so this quote '
            . 'was escalated instead of serviced.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
                'problems' => $e->problems,
            ]);

            // The problems stay in the log line above. escalate() writes into
            // the conversation the CUSTOMER reads and deliberately accepts no
            // text, so there is nowhere to pass them even by accident.
            $this->escalator->escalate($gateway, $snapshot, QuoteEscalationReason::NotConfigured);

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
