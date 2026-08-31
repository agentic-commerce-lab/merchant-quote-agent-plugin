<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationPass;

/**
 * Folds a finished pass's outcome and prompt hashes onto the draft. A no-op
 * when there is no pass — the error path in finish() — leaving whatever the
 * stages already recorded.
 *
 * Split out of DecisionRecorder so this branching counts against this small
 * class's complexity budget rather than DecisionRecorder's: a private helper
 * would still sum into the same class-scoped total.
 */
final class PassOutcome
{
    private function __construct() {}

    public static function applyTo(DecisionDraft $draft, ?NegotiationPass $pass): void
    {
        if ($pass === null) {
            return;
        }

        $draft->outcome = $pass->outcome->value;
        $draft->extractPromptHash = $pass->extractHash ?? $draft->extractPromptHash;
        $draft->negotiatePromptHash = $pass->negotiateHash ?? $draft->negotiatePromptHash;
        $draft->replyPromptHash = $pass->replyHash ?? $draft->replyPromptHash;
        // `??` keeps a reason OfferProposer already recorded (recordProposal)
        // when the pass itself carries none.
        $draft->escalationReason = $pass->escalationReason?->value ?? $draft->escalationReason;
    }
}
