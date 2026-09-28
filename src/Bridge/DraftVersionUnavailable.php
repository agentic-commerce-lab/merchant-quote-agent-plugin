<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/** SwagCommercial's license toggle is off, so no gateway can serve a draft version. */
final class DraftVersionUnavailable extends \RuntimeException
{
    public static function forVersion(string $versionId): self
    {
        return new self(sprintf(
            'Draft version %s cannot be served: the SwagCommercial gateway is unavailable.',
            $versionId,
        ));
    }
}
