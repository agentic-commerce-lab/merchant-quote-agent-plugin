<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\AccessTokenSubjectReaderInterface;
use MerchantQuoteAgentPlugin\Identity\AgentCustomerCredential;
use MerchantQuoteAgentPlugin\Identity\OAuthAccessTokenInfo;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\RequestContext;

/**
 * Builders for AgentCustomerAuthenticatorTest that need no TestCase — fakes,
 * not mocks. Separate from the test class so that class stays under mago's
 * too-many-methods ceiling.
 */
final class AgentCustomerAuthenticatorFixture
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const CUSTOMER_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f02';

    private function __construct() {}

    public static function credential(): AgentCustomerCredential
    {
        return AgentCustomerCredential::fromAccessToken('ucp_at_abc');
    }

    public static function requestContext(): RequestContext
    {
        return new RequestContext('shop.example');
    }

    /**
     * @param list<string> $scopes
     */
    public static function tokenInfo(
        ?string $salesChannelId = null,
        array $scopes = ['dev.ucp.shopping.cart:manage'],
    ): OAuthAccessTokenInfo {
        return new OAuthAccessTokenInfo(
            $salesChannelId ?? self::SALES_CHANNEL_ID,
            'agent-client',
            self::CUSTOMER_ID,
            $scopes,
        );
    }

    public static function reader(?OAuthAccessTokenInfo $info): AccessTokenSubjectReaderInterface
    {
        return new class($info) implements AccessTokenSubjectReaderInterface {
            public function __construct(
                private readonly ?OAuthAccessTokenInfo $info,
            ) {}

            #[\Override]
            public function find(
                #[\SensitiveParameter]
                string $accessToken,
                string $salesChannelId,
            ): ?OAuthAccessTokenInfo {
                return $this->info;
            }
        };
    }

    /**
     * @return CustomerContextResolverInterface&object{resolvedCustomerId: ?string}
     */
    public static function resolver(
        SalesChannelContext $context,
        string $salesChannelId,
    ): CustomerContextResolverInterface {
        return new class($context, $salesChannelId) implements CustomerContextResolverInterface {
            public ?string $resolvedCustomerId = null;

            public function __construct(
                private readonly SalesChannelContext $context,
                private readonly string $salesChannelId,
            ) {}

            #[\Override]
            public function resolveSalesChannel(RequestContext $context): SalesChannelResolution
            {
                return new SalesChannelResolution($this->salesChannelId, 'l', 'c', 'd');
            }

            #[\Override]
            public function resolveByHost(?string $host): ?SalesChannelResolution
            {
                return new SalesChannelResolution($this->salesChannelId, 'l', 'c', 'd');
            }

            #[\Override]
            public function resolveForCustomer(
                string $customerId,
                RequestContext $context,
                ?SalesChannelResolution $resolution = null,
            ): SalesChannelContext {
                $this->resolvedCustomerId = $customerId;

                return $this->context;
            }
        };
    }
}
