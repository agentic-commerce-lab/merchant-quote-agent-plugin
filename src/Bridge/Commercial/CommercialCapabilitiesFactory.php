<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;

/**
 * Builds {@see CommercialCapabilities} by asking the DAL what this shop's
 * SwagCommercial actually declares.
 *
 * Field presence, never a version number. SwagCommercial ships schema in patch
 * releases, so a version comparison would be wrong the first time somebody
 * backports `requested_price` — and the DAL definition is the same source the
 * read path will hit a microsecond later, so there is no window where the two
 * can disagree.
 *
 * `has()` on the registry before `getByEntityName()`: the latter throws on an
 * unknown entity, and a shop with SwagCommercial inactive has no `quote`
 * entity at all. That cannot happen behind `services.php`'s availability guard,
 * but a factory that throws during container warmup takes the whole shop down,
 * so it degrades to "no capabilities" instead.
 */
final readonly class CommercialCapabilitiesFactory
{
    private const QUOTE_LINE_ITEM = 'quote_line_item';

    private const QUOTE_COMMENT = 'quote_comment';

    public function __construct(
        private DefinitionInstanceRegistry $registry,
    ) {}

    public function create(): CommercialCapabilities
    {
        return new CommercialCapabilities(
            lineItemAsks: $this->hasField(self::QUOTE_LINE_ITEM, 'requestedPrice'),
            softDeleteLines: $this->hasField(self::QUOTE_LINE_ITEM, 'deletedAt'),
            lineScopedComments: $this->hasField(self::QUOTE_COMMENT, 'quoteLineItemId'),
            draftBeforeSend: class_exists(CommercialAvailability::QUOTE_SEND_REQUEST_ROUTE),
        );
    }

    private function hasField(string $entityName, string $propertyName): bool
    {
        if (!$this->registry->has($entityName)) {
            return false;
        }

        return $this->registry->getByEntityName($entityName)->getFields()->get($propertyName) !== null;
    }
}
