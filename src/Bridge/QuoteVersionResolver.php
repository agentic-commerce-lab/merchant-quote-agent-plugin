<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;

/** Maps QuoteVersion onto the DAL version ids SwagCommercial uses. */
final class QuoteVersionResolver
{
    /**
     * Mirrors Shopware\Commercial\B2B\QuoteManagement\Entity\Quote\QuoteEntity::SNAPSHOT_VERSION_ID.
     * A literal because that class need not be loadable — if SwagCommercial
     * ever changes it, tests/Integration/ is what catches it.
     */
    public const SNAPSHOT_VERSION_ID = '019cfaaf020219939ba2eea26ba651ae';

    public function contextFor(Context $context, QuoteVersion $version): Context
    {
        return match ($version) {
            QuoteVersion::Live => $context->createWithVersionId(Defaults::LIVE_VERSION),
            QuoteVersion::Snapshot => $context->createWithVersionId(self::SNAPSHOT_VERSION_ID),
        };
    }
}
