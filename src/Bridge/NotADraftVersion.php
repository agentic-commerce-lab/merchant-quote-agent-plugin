<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/** A version id that is not a Draft Mode draft: the live version, the snapshot lane, or no uuid at all. */
final class NotADraftVersion extends \RuntimeException
{
    public static function forId(string $versionId): self
    {
        return new self(sprintf(
            'Version %s is not a draft version, so it cannot be drafted in, merged or deleted as one.',
            $versionId,
        ));
    }
}
