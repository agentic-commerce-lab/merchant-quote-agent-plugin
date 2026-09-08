<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Identity;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The identity to publish and sign under, for one sales channel or one request
 * host.
 *
 * A thin facade: the host lookup and the organization-name lookup live in
 * SalesChannelHostReader and A2cnOrganizationNameReader — splitting on that
 * seam is what keeps this class under the per-class cyclomatic-complexity
 * gate.
 *
 * Not `final`: the tests substitute it.
 */
class A2cnIdentityResolver
{
    public const ORGANIZATION_CONFIG_KEY = 'MerchantQuoteAgentPlugin.config.a2cnOrganizationName';

    private readonly SalesChannelHostReader $hosts;

    private readonly A2cnOrganizationNameReader $organizationNames;

    public function __construct(
        private readonly A2cnKeyStore $keys,
        SystemConfigService $systemConfig,
        Connection $connection,
    ) {
        $this->hosts = new SalesChannelHostReader($connection);
        $this->organizationNames = new A2cnOrganizationNameReader($systemConfig, $connection);
    }

    /** @throws MissingSigningKey|\Doctrine\DBAL\Exception */
    public function forHost(string $host, ?string $salesChannelId = null): A2cnIdentity
    {
        return A2cnIdentity::forHost(
            $host,
            $this->keys->current()->kid,
            $this->organizationNames->nameFor($salesChannelId),
        );
    }

    /**
     * The identity for a sales channel, from its primary domain.
     *
     * Null when the channel has no domain to be a did:web authority — a shop
     * reachable at no URL cannot publish a DID document either.
     *
     * @throws MissingSigningKey|\Doctrine\DBAL\Exception
     */
    public function forSalesChannel(string $salesChannelId): ?A2cnIdentity
    {
        $host = $this->hosts->hostFor($salesChannelId);

        return $host === null ? null : $this->forHost($host, $salesChannelId);
    }
}
