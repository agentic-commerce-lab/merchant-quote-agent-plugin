<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing\Exception;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

final class QuoteServicingUnavailableException extends QuoteServicingException implements
    UnrecoverableExceptionInterface
{
    public static function gatewayNotAvailable(string $quoteId): self
    {
        return new self(\sprintf(
            'Quote servicing unavailable for quote "%s": SwagCommercial is absent or unlicensed.',
            $quoteId,
        ));
    }
}
