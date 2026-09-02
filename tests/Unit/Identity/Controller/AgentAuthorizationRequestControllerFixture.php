<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Controller;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\Authorization\AgentAuthorizationRegistrar;
use MerchantQuoteAgentPlugin\Identity\Authorization\PayloadFields;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use MerchantQuoteAgentPlugin\Identity\Authorization\SalesChannelDomainUrlReader;
use MerchantQuoteAgentPlugin\Identity\Controller\AgentAuthorizationRequestController;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

/**
 * Collaborators for the controller test. A fixture class rather than inline
 * anonymous classes so the three test methods read as one shape, and so each
 * test can assert against the same recording store the controller writes
 * through — the refusal tests must pin the write, not just the throw.
 */
final class AgentAuthorizationRequestControllerFixture
{
    public const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private function __construct() {}

    public static function context(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            [],
            'https://agent.example/.well-known/ucp',
            new PlatformProfile('2026-04-08', [], [], []),
            [],
            true,
        );
    }

    /** A recording double, so a refusal test can assert nothing was persisted. */
    public static function store(): PendingAuthorizationStoreInterface
    {
        return new class implements PendingAuthorizationStoreInterface {
            /** @var list<PendingAuthorization> */
            public array $stored = [];

            #[\Override]
            public function store(PendingAuthorization $pending, int $ttlSeconds): string
            {
                $this->stored[] = $pending;

                return 'handle-value';
            }

            #[\Override]
            public function find(#[\SensitiveParameter] string $handle): ?PendingAuthorization
            {
                return null;
            }

            #[\Override]
            public function consume(#[\SensitiveParameter] string $handle): ?PendingAuthorization
            {
                return null;
            }
        };
    }

    public static function build(PendingAuthorizationStoreInterface $store): AgentAuthorizationRequestController
    {
        $resolver = new class implements CustomerContextResolverInterface {
            #[\Override]
            public function resolveSalesChannel(RequestContext $context): SalesChannelResolution
            {
                return new SalesChannelResolution(
                    AgentAuthorizationRequestControllerFixture::SALES_CHANNEL_ID,
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0a',
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0b',
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0c',
                );
            }

            #[\Override]
            public function resolveForCustomer(
                string $customerId,
                RequestContext $context,
                ?SalesChannelResolution $resolution = null,
            ): SalesChannelContext {
                throw new \LogicException('not used in this test');
            }
        };

        $domains = new class extends SalesChannelDomainUrlReader {
            public function __construct() {}

            #[\Override]
            public function urlFor(?string $domainId): ?string
            {
                return 'https://shop.example';
            }
        };

        return new AgentAuthorizationRequestController(
            new AgentAuthorizationRegistrar($store, new PayloadFields()),
            $resolver,
            $domains,
        );
    }
}
