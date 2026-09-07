<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

/**
 * The seam that keeps QuoteEscalator free of Shopware, in the same shape as
 * DecisionRecordWriterInterface: the escalator decides that a merchant should
 * be told, this delivers it, and only the implementation knows about flows,
 * notifications or the DAL.
 *
 * Implementations MUST NOT throw. An escalation's first duty is telling the
 * buyer and stamping the marker, and a notification channel that fails must
 * not cost the buyer their answer or re-run the whole pass. The Shopware
 * implementation catches and logs; QuoteEscalator guards the call as a
 * backstop for implementations that forget.
 */
interface EscalationNotifierInterface
{
    public function notify(EscalationNotice $notice): void;
}
