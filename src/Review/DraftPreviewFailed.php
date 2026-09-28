<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

final class DraftPreviewFailed extends \RuntimeException
{
    public static function after(\Throwable $previous): self
    {
        return new self('The draft preview could not be completed. No edits were saved.', previous: $previous);
    }
}
