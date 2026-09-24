<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

final class DraftSendFailed extends \RuntimeException
{
    public static function after(\Throwable $previous): self
    {
        return new self('The draft could not be sent. Check the quote before trying again.', previous: $previous);
    }
}
