<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;

/** Resolves a queued trigger before the preflight can mutate a quote. */
final class ServicingTriggerResolver
{
    private function __construct() {}

    /** @throws \ValueError */
    public static function resolve(
        ServiceQuoteMessage $message,
        QuoteSnapshot $snapshot,
        int $attempt,
        ServicingJournal $journal,
    ): ServicingTriggerReason {
        try {
            return ServicingTriggerReason::from($message->reason);
        } catch (\ValueError $e) {
            $journal->skip(
                SkipSource::Handler,
                SkipReason::StaleTrigger,
                SkipContext::forMessage($message, $attempt, $snapshot->identity->customerId),
            );

            throw $e;
        }
    }
}
