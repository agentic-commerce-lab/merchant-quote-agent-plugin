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
use MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization\AgentAuthorizationRegistrarFixture;
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

    public const CLIENT_ID = 'https://agent.example/.well-known/ucp';

    /** The base {@see domains()} returns for the domain it is asked about. */
    public const DOMAIN_BASE = 'https://shop.example';

    /** The domain id {@see build()}'s context resolver reports for SALES_CHANNEL_ID. */
    public const DOMAIN_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f0c';

    private function __construct() {}

    public static function context(): RequestContext
    {
        return new RequestContext(
            'shop.example',
            [],
            self::CLIENT_ID,
            new PlatformProfile('2026-04-08', [], [], []),
            [],
            true,
        );
    }

    /**
     * A payload the registrar accepts, matching {@see context()}'s verified client_id.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'https://agent.example/callback',
            'scope' => 'dev.ucp.shopping.order:read',
            'state' => 'state-value',
            'code_challenge' => AgentAuthorizationRegistrarFixture::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
        ];
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

    public static function build(
        PendingAuthorizationStoreInterface $store,
        ?SalesChannelDomainUrlReader $domains = null,
    ): AgentAuthorizationRequestController {
        $resolver = new class implements CustomerContextResolverInterface {
            #[\Override]
            public function resolveSalesChannel(RequestContext $context): SalesChannelResolution
            {
                return new SalesChannelResolution(
                    AgentAuthorizationRequestControllerFixture::SALES_CHANNEL_ID,
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0a',
                    '0191d3d0a0b071bd9c1a0d9d1a3f9f0b',
                    AgentAuthorizationRequestControllerFixture::DOMAIN_ID,
                );
            }

            #[\Override]
            public function resolveByHost(?string $host): ?SalesChannelResolution
            {
                throw new \LogicException('not used in this test');
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

        return new AgentAuthorizationRequestController(
            AgentAuthorizationRegistrarFixture::registrar($store),
            $resolver,
            $domains ?? self::domains(),
        );
    }

    /**
     * A RECORDING double. The argument matters as much as the return value:
     * asking for the right domain id IS the sales-channel binding — the
     * consent URL has to be built on the domain the agent registered against,
     * not on whatever host the storefront answers on. A double that ignored
     * its argument let the success test prove only that the base came from
     * `urlFor()`, never that the right domain was asked for.
     *
     * @return SalesChannelDomainUrlReader&object{askedFor: list<?string>}
     */
    public static function domains(): object
    {
        return new class extends SalesChannelDomainUrlReader {
            /** @var list<?string> */
            public array $askedFor = [];

            public function __construct() {}

            #[\Override]
            public function urlFor(?string $domainId): ?string
            {
                $this->askedFor[] = $domainId;

                return AgentAuthorizationRequestControllerFixture::DOMAIN_BASE;
            }
        };
    }
}
