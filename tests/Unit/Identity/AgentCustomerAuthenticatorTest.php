<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Bridge\CustomerContextResolverInterface;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelResolution;
use MerchantQuoteAgentPlugin\Identity\AccessTokenSubjectReaderInterface;
use MerchantQuoteAgentPlugin\Identity\AgentCustomerAuthenticator;
use MerchantQuoteAgentPlugin\Identity\AgentCustomerCredential;
use MerchantQuoteAgentPlugin\Identity\OAuthAccessTokenInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * @mago-expect lint:too-many-methods
 *
 * Five behaviours, each needing its own fixture combination; the rest are
 * private builders shared across them, not independent responsibilities.
 */
#[CoversClass(AgentCustomerAuthenticator::class)]
final class AgentCustomerAuthenticatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const CUSTOMER_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f02';

    public function testItResolvesTheTokenSubjectToTheCustomersContext(): void
    {
        $context = $this->customerContext(self::CUSTOMER_ID);
        $resolver = $this->resolver();
        $resolver
            ->expects(self::once())
            ->method('resolveForCustomer')
            ->with(self::CUSTOMER_ID, self::isInstanceOf(RequestContext::class))
            ->willReturn($context);

        $authenticator = new AgentCustomerAuthenticator($resolver, $this->reader($this->tokenInfo()));

        self::assertSame($context, $authenticator->authenticate($this->credential(), $this->requestContext()));
    }

    public function testItRejectsAnUnknownExpiredOrRevokedToken(): void
    {
        $authenticator = new AgentCustomerAuthenticator($this->resolver(), $this->reader(null));

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Access token is invalid, expired, or revoked.');

        $authenticator->authenticate($this->credential(), $this->requestContext());
    }

    public function testItRejectsATokenIssuedForAnotherSalesChannel(): void
    {
        $foreign = new OAuthAccessTokenInfo('0191d3d0a0b071bd9c1a0d9d1a3f9fff', 'agent-client', self::CUSTOMER_ID, []);
        $authenticator = new AgentCustomerAuthenticator($this->resolver(), $this->reader($foreign));

        $this->expectException(UnauthorizedHttpException::class);

        $authenticator->authenticate($this->credential(), $this->requestContext());
    }

    public function testItRejectsATokenMissingARequiredScopeWhenOneIsAsked(): void
    {
        $authenticator = new AgentCustomerAuthenticator($this->resolver(), $this->reader($this->tokenInfo()));

        $this->expectException(UnauthorizedHttpException::class);

        $authenticator->authenticate($this->credential(), $this->requestContext(), 'com.shopware.quote:manage');
    }

    public function testItFailsWhenTheSubjectIsNoLongerACustomer(): void
    {
        $resolver = $this->resolver();
        $resolver->method('resolveForCustomer')->willReturn($this->customerContext(null));

        $authenticator = new AgentCustomerAuthenticator($resolver, $this->reader($this->tokenInfo()));

        $this->expectException(ValidationException::class);

        $authenticator->authenticate($this->credential(), $this->requestContext());
    }

    private function credential(): AgentCustomerCredential
    {
        return AgentCustomerCredential::fromAccessToken('ucp_at_abc');
    }

    private function requestContext(): RequestContext
    {
        return new RequestContext('shop.example');
    }

    private function tokenInfo(): OAuthAccessTokenInfo
    {
        return new OAuthAccessTokenInfo(
            self::SALES_CHANNEL_ID,
            'agent-client',
            self::CUSTOMER_ID,
            ['dev.ucp.shopping.cart:manage'],
        );
    }

    private function reader(?OAuthAccessTokenInfo $info): AccessTokenSubjectReaderInterface
    {
        $reader = $this->createMock(AccessTokenSubjectReaderInterface::class);
        $reader->method('find')->willReturn($info);

        return $reader;
    }

    private function resolver(): CustomerContextResolverInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $resolver = $this->createMock(CustomerContextResolverInterface::class);
        $resolver
            ->method('resolveSalesChannel')
            ->willReturn(new SalesChannelResolution(self::SALES_CHANNEL_ID, 'l', 'c', 'd'));

        return $resolver;
    }

    private function customerContext(?string $customerId): SalesChannelContext
    {
        $customer = null;

        if ($customerId !== null) {
            $customer = new CustomerEntity();
            $customer->setId($customerId);
        }

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);

        return $context;
    }
}
