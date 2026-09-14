<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The ISO 4217 code a storefront trades in.
 *
 * The seller mandate has to name one currency for `max_commitment_value`,
 * which is a scalar in the spec. The merchant's own ceiling is usually written
 * as a bare number meaning "whatever the currency", so the storefront the
 * mandate is being served for is what turns that into a claim a buyer can
 * read: it is the currency any quote on this domain will actually be
 * denominated in.
 *
 * DBAL rather than the DAL, and shaped exactly like SalesChannelHostReader
 * next to it: one indexed read of a column the discovery documents need, on a
 * path that must not fail loudly. Null on anything unexpected — an unpublished
 * ceiling is a smaller problem than a 500 on a well-known URL.
 *
 * Not `final`: the tests substitute it, the same way they substitute
 * A2cnKeyStore and QuoteTerminalStateReader.
 */
class SalesChannelCurrencyReader
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function isoFor(?string $salesChannelId): ?string
    {
        if ($salesChannelId === null || !Uuid::isValid($salesChannelId)) {
            return null;
        }

        try {
            $iso = $this->connection->fetchOne('SELECT `currency`.`iso_code`
                 FROM `sales_channel`
                 INNER JOIN `currency` ON `currency`.`id` = `sales_channel`.`currency_id`
                 WHERE `sales_channel`.`id` = :id', [
                'id' => Uuid::fromHexToBytes($salesChannelId),
            ]);
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        return \is_string($iso) && $iso !== '' ? $iso : null;
    }
}
