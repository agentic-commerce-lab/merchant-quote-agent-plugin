<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;

/** Commits the crash counter and baseline before a pass can enter the pipeline. */
final class AttemptStampWriter
{
    private function __construct() {}

    /** @throws \Throwable */
    public static function write(
        QuoteGatewayInterface $gateway,
        ServiceQuoteMessage $message,
        QuoteSnapshot $snapshot,
        int $attempts,
        ServicingJournal $journal,
    ): void {
        try {
            $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
                ServiceQuoteHandler::ATTEMPTS_KEY => $attempts + 1,
                ...QuoteBaseline::stampOrExtend($snapshot),
            ]));
        } catch (QuoteNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $journal->skip(
                SkipSource::Handler,
                SkipReason::AttemptWriteFailed,
                SkipContext::forMessage($message, $attempts, $snapshot->identity->customerId),
            );

            throw $e;
        }
    }
}
