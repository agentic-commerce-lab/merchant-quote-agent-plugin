<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Strategy\StrategyAssignmentResolver;
use MerchantQuoteAgentPlugin\Strategy\UnknownStrategy;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;

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
 *
 * Only the misconfiguration path leaves a row in `merchant_quote_agent_decision`
 * (via recordRefusal(), below). It is the only one of the three that DOES
 * something a merchant or #21's run would look for: an escalation marker, a
 * possible buyer comment, an admin notification. The kill switch and the
 * terminal-state refusal are both silent by design — a row per buyer comment
 * announcing that the agent is paused, or that an accepted quote is not being
 * touched, is not an audit event, it is noise.
 *
 * Also answers *which strategy*, not only *whether and with what settings*:
 * after the off-switch and the misconfiguration check both clear, the
 * assignment ladder (StrategyAssignmentResolver) gets one chance to replace
 * the sales-channel's configured strategy with a customer pin, a matching
 * rule or a split arm. It runs strictly after the off-switch, never before,
 * so a paused agent never pays for a quote read, a context restore or a cart
 * conversion the rule rung can cost. An assignment naming a missing or
 * archived strategy is configuration the merchant made in our own UI, and is
 * escalated exactly like a dangling config key already is.
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
        private DecisionRecorder $recorder,
        private StrategyAssignmentResolver $assignments,
    ) {}

    /** Null means "do not service this quote"; the reason has already been handled. */
    public function check(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        PassContext $context,
    ): ?QuoteAgentSettings {
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
            $this->record($snapshot, $context, $e->problems);

            return null;
        }

        if ($settings === null) {
            $this->logger->debug('The quote agent is switched off for this sales channel.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
            ]);

            return null;
        }

        // After the off-switch, never before it: a paused agent must not pay
        // for the quote read and the cart conversion the rule rung can cost.
        try {
            $assigned = $this->assignments->assign(
                $snapshot->identity->quoteId,
                $snapshot->identity->customerId,
                $snapshot->identity->salesChannelId,
                Context::createDefaultContext(),
            );
        } catch (UnknownStrategy $e) {
            $this->logger->error('A strategy assignment points at a strategy that is missing or archived, so this '
            . 'quote was escalated instead of serviced with a posture nobody chose.', [
                'quoteId' => $snapshot->identity->quoteId,
                'salesChannelId' => $snapshot->identity->salesChannelId,
                'problem' => $e->getMessage(),
            ]);

            $this->escalator->escalate($gateway, $snapshot, QuoteEscalationReason::NotConfigured);
            $this->record($snapshot, $context, [$e->getMessage()]);

            return null;
        }

        return $assigned === null ? $settings : $settings->withStrategy($assigned->strategy, $assigned->source);
    }

    /**
     * The audit write may never cost the pass. NegotiationPipeline::record()
     * makes the same trade for the same reason: a throw would roll the message
     * back into Messenger's retry, and the redelivery would re-run a preflight
     * whose escalation has already landed. A missing record beats that. The
     * error log line above is the backstop.
     *
     * A separate method rather than a try inside the catch above: the catch is
     * already nested inside check(), and a third level there would sit at
     * mago's nesting cap.
     *
     * Called after escalate(), never before: the escalation is the thing that
     * must happen and this describes it.
     *
     * @param list<string> $problems
     */
    private function record(QuoteSnapshot $snapshot, PassContext $context, array $problems): void
    {
        try {
            $this->recorder->recordRefusal($snapshot, $context, QuoteEscalationReason::NotConfigured, $problems);
        } catch (\Throwable $e) {
            $this->logger->error('The quote agent escalated a misconfigured quote but could not record it; '
            . 'the escalation itself stands.', [
                'quoteId' => $snapshot->identity->quoteId,
                'exception' => $e,
            ]);
        }
    }
}
