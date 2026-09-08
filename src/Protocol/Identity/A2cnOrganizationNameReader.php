<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The organization name to publish for a sales channel: the merchant's own
 * configured value when they set one, the sales channel's own name otherwise —
 * a published seller mandate should not say "Merchant".
 *
 * Split out of A2cnIdentityResolver for the same reason as
 * SalesChannelHostReader: the seam is which question is being answered.
 */
final readonly class A2cnOrganizationNameReader
{
    public function __construct(
        private SystemConfigService $systemConfig,
        private Connection $connection,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    public function nameFor(?string $salesChannelId): string
    {
        $configured = $this->systemConfig->getString(A2cnIdentityResolver::ORGANIZATION_CONFIG_KEY, $salesChannelId);
        if (trim($configured) !== '') {
            return trim($configured);
        }

        if ($salesChannelId === null || !Uuid::isValid($salesChannelId)) {
            return 'Merchant';
        }

        $name = $this->connection->fetchOne('SELECT `name` FROM `sales_channel_translation` WHERE `sales_channel_id` = :id LIMIT 1', [
            'id' => Uuid::fromHexToBytes($salesChannelId),
        ]);

        return \is_string($name) && $name !== '' ? $name : 'Merchant';
    }
}
