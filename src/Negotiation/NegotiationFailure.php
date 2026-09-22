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
            // Issue #169: kept as NeedsHumanReview rather than a new case.
            // CrossCustomerRead's own docblock says this "should be
            // unreachable" -- a security guard against a bug, not a customer
            // ask -- so there is no honest customer-facing name for it beyond
            // the generic "a human was needed, cause not recorded" this case
            // now carries.
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
