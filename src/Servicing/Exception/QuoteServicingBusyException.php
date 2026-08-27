<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Exception;

use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;

final class QuoteServicingBusyException extends QuoteServicingException implements RecoverableExceptionInterface
{
    private const RETRY_DELAY_MILLISECONDS = 5000;

    public static function quoteAlreadyActive(string $quoteId): self
    {
        return new self(\sprintf('Quote servicing is already active for quote "%s".', $quoteId));
    }

    public function getRetryDelay(): ?int
    {
        return self::RETRY_DELAY_MILLISECONDS;
    }
}
