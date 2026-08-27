<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Exception;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

final class QuoteServicingAttemptsExhaustedException extends QuoteServicingException implements
    UnrecoverableExceptionInterface
{
    public static function deliveryLimitExceeded(string $messageId, string $quoteId, int $deliveryCount): self
    {
        return new self(\sprintf(
            'Quote servicing attempts exhausted for message "%s" and quote "%s" after %d deliveries.',
            $messageId,
            $quoteId,
            $deliveryCount,
        ));
    }
}
