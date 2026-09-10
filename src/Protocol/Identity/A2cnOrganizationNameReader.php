<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The organization name to publish for a sales channel, first of:
 *
 * 1. the merchant's own value, set on the Agent access page;
 * 2. the shop name from Settings → Shop → Basic information;
 * 3. the sales channel's own name;
 * 4. "Merchant", which a published seller mandate should never have to say.
 *
 * The shop name sits above the channel name because it is the name the shop
 * already presents to customers, while a channel's name is an internal admin
 * label — "Storefront" on a stock installation, against a shop actually called
 * "Demostore". Both are read through SystemConfigService, so a per-channel
 * override wins over the global value on either.
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

    /**
     * Core's own key for the shop name, `basicInformation.xml`. A literal
     * rather than a class constant because core exposes none.
     */
    public const SHOP_NAME_CONFIG_KEY = 'core.basicInformation.shopName';

    /** @throws \Doctrine\DBAL\Exception */
    public function nameFor(?string $salesChannelId): string
    {
        $configured = $this->configured(A2cnIdentityResolver::ORGANIZATION_CONFIG_KEY, $salesChannelId);
        if ($configured !== null) {
            return $configured;
        }

        $shopName = $this->configured(self::SHOP_NAME_CONFIG_KEY, $salesChannelId);
        if ($shopName !== null) {
            return $shopName;
        }

        if ($salesChannelId === null || !Uuid::isValid($salesChannelId)) {
            return 'Merchant';
        }

        $name = $this->connection->fetchOne('SELECT `name` FROM `sales_channel_translation` WHERE `sales_channel_id` = :id LIMIT 1', [
            'id' => Uuid::fromHexToBytes($salesChannelId),
        ]);

        return \is_string($name) && $name !== '' ? $name : 'Merchant';
    }

    /** A configured value, trimmed, or null when it is unset or blank. */
    private function configured(string $key, ?string $salesChannelId): ?string
    {
        $value = trim($this->systemConfig->getString($key, $salesChannelId));

        return $value === '' ? null : $value;
    }
}
