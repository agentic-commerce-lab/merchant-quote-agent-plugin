<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\History\CrossCustomerRead;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use Psr\Log\LoggerInterface;

/** Escalates known failures, retaining the exception in logs and safe account-boundary details in audit. */
final readonly class NegotiationFailure
{
    public function __construct(
        private OfferRound $round,
        private DecisionRecorder $recorder,
        private LoggerInterface $logger,
    ) {}

    public function escalate(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        ModelUnavailable|CrossCustomerRead $error,
    ): NegotiationPass {
        $reason = QuoteEscalationReason::ModelUnavailable;
        $message = 'The model was unavailable, so this quote goes to a human.';

        if ($error instanceof CrossCustomerRead) {
            $reason = QuoteEscalationReason::NeedsHumanReview;
            $message = 'An account history read was refused; this quote goes to a human.';
            $this->recorder->recordProposal(null, ProposedAnswer::escalate($reason, $error->getMessage(), null));
        }

        $this->logger->error($message, [
            'quoteId' => $snapshot->identity->quoteId,
            'exception' => $error,
        ]);

        return $this->round->escalated($gateway, $snapshot, $reason, null, null);
    }
}
