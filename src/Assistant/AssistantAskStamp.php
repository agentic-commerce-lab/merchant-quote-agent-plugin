<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Records whether a quote's price ask was typed by the buyer or proposed by
 * the assistant and agreed to.
 *
 * Both arrive at the policy engine as the same number, and without this the
 * decision log says "the buyer asked for 10%" about a figure a model wrote.
 * The admin can then show "assistant proposed, buyer confirmed" instead.
 *
 * Deliberately NOT read by Negotiation\CappedAuthority: an assistant-proposed
 * figure caps exactly like a typed one today. Narrowing the cap for
 * model-authored asks is a policy decision and belongs in its own spec — this
 * class only makes the distinction visible, so that decision can be taken on
 * evidence rather than on guesses.
 *
 * `$gateway` is nullable, defaulted and last, matching A2cnSessionStamp and
 * SellerActEmitter: QuoteGatewayFactory::create() returns null where
 * SwagCommercial's classes exist but the licence does not.
 *
 * Fail-open, same as A2cnSessionStamp: the quote already exists and the
 * buyer is waiting on it, so losing the provenance note is a warning, while
 * losing the buyer their quote over one would not be a trade worth making.
 */
final readonly class AssistantAskStamp
{
    public const ASK_SOURCE_KEY = 'merchantQuoteAgentAssistantAsk';

    public function __construct(
        private LoggerInterface $logger,
        private ?QuoteGatewayInterface $gateway = null,
    ) {}

    public function stamp(QuoteSnapshot $snapshot, string $targetSource): void
    {
        if ($this->gateway === null) {
            return;
        }

        try {
            $this->gateway->updateQuote($snapshot->id, new QuoteUpdate(customFields: [
                self::ASK_SOURCE_KEY => $targetSource,
            ]));
        } catch (\Throwable $error) {
            $this->logger->warning('Could not record who authored an assistant quote ask.', [
                'quoteId' => $snapshot->id,
                'exception' => $error,
            ]);
        }
    }
}
