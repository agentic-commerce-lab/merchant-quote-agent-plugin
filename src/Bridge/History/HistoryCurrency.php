<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/** Reads only the currency associated with this verified quote or order. */
final readonly class HistoryCurrency
{
    public static function of(Entity $entity): ?string
    {
        $currency = $entity->get('currency');
        $iso = $currency instanceof Entity ? $currency->get('isoCode') : null;
        return \is_string($iso) && trim($iso) !== '' ? $iso : null;
    }
}
