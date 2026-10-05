<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionDraft;
use MerchantQuoteAgentPlugin\Audit\DecisionRecordWriterInterface;

/**
 * The seam that lets a replay run production code and leave no trace.
 *
 * OfferProposer records through DecisionRecorder, which persists through this
 * interface. A replay is not a decision -- no buyer was answered and no quote
 * was written -- so a replayed pass must never reach
 * merchant_quote_agent_decision, or the audit trail would claim passes that
 * never happened.
 *
 * It still keeps the one number worth keeping: what the night cost. A merchant
 * paying for this must see the real total, not the projection in the config
 * help text.
 */
final class TallyingDecisionWriter implements DecisionRecordWriterInterface
{
    public int $promptTokens = 0;

    public int $completionTokens = 0;

    #[\Override]
    public function write(DecisionDraft $draft): void
    {
        $this->promptTokens += $draft->promptTokens ?? 0;
        $this->completionTokens += $draft->completionTokens ?? 0;
    }
}
