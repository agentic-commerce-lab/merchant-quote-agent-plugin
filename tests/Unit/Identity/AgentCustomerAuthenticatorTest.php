<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity;

use MerchantQuoteAgentPlugin\Identity\AgentCustomerAuthenticator;
use MerchantQuoteAgentPlugin\Identity\OAuthAccessTokenInfo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Ucp\Sdk\Exception\ValidationException;

#[CoversClass(AgentCustomerAuthenticator::class)]
final class AgentCustomerAuthenticatorTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    private const CUSTOMER_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f02';

    public function testItResolvesTheTokenSubjectToTheCustomersContext(): void
    {
        $context = $this->customerContext(self::CUSTOMER_ID);
        $resolver = AgentCustomerAuthenticatorFixture::resolver($context, self::SALES_CHANNEL_ID);
        $authenticator = new AgentCustomerAuthenticator(
            $resolver,
            AgentCustomerAuthenticatorFixture::reader(AgentCustomerAuthenticatorFixture::tokenInfo()),
        );

        $result = $authenticator->authenticate(
            AgentCustomerAuthenticatorFixture::credential(),
            AgentCustomerAuthenticatorFixture::requestContext(),
        );

        self::assertSame($context, $result);
        self::assertSame(self::CUSTOMER_ID, $resolver->resolvedCustomerId);
    }

    public function testItRejectsAnUnknownExpiredOrRevokedToken(): void
    {
        $resolver = AgentCustomerAuthenticatorFixture::resolver(
            $this->customerContext(self::CUSTOMER_ID),
            self::SALES_CHANNEL_ID,
        );
        $authenticator = new AgentCustomerAuthenticator($resolver, AgentCustomerAuthenticatorFixture::reader(null));

        $this->expectException(UnauthorizedHttpException::class);
        $this->expectExceptionMessage('Access token is invalid, expired, or revoked.');

        $authenticator->authenticate(
            AgentCustomerAuthenticatorFixture::credential(),
            AgentCustomerAuthenticatorFixture::requestContext(),
        );
    }

    public function testItRejectsATokenIssuedForAnotherSalesChannel(): void
    {
        $foreign = new OAuthAccessTokenInfo('0191d3d0a0b071bd9c1a0d9d1a3f9fff', 'agent-client', self::CUSTOMER_ID, []);
        $resolver = AgentCustomerAuthenticatorFixture::resolver(
            $this->customerContext(self::CUSTOMER_ID),
            self::SALES_CHANNEL_ID,
        );
        $authenticator = new AgentCustomerAuthenticator($resolver, AgentCustomerAuthenticatorFixture::reader($foreign));

        $this->expectException(UnauthorizedHttpException::class);

        $authenticator->authenticate(
            AgentCustomerAuthenticatorFixture::credential(),
            AgentCustomerAuthenticatorFixture::requestContext(),
        );
    }

    public function testItRejectsATokenMissingARequiredScopeWhenScopesAreEnforced(): void
    {
        $authenticator = $this->authenticator(['dev.ucp.shopping.cart:manage'], true);

        try {
            $authenticator->authenticate(
                AgentCustomerAuthenticatorFixture::credential(),
                AgentCustomerAuthenticatorFixture::requestContext(),
                ['com.shopware.quote:manage'],
            );
            self::fail('A token without the required scope was accepted.');
        } catch (AccessDeniedHttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertStringContainsString('com.shopware.quote:manage', $exception->getMessage());
        }
    }

    public function testItNamesEveryRequiredScopeTheTokenLacks(): void
    {
        $authenticator = $this->authenticator(['com.shopware.quote:manage'], true);

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('dev.ucp.shopping.order:manage');

        $authenticator->authenticate(
            AgentCustomerAuthenticatorFixture::credential(),
            AgentCustomerAuthenticatorFixture::requestContext(),
            ['com.shopware.quote:manage', 'dev.ucp.shopping.order:manage'],
        );
    }

    public function testItAcceptsATokenCarryingEveryRequiredScope(): void
    {
        $authenticator = $this->authenticator(['com.shopware.quote:manage', 'dev.ucp.shopping.order:manage'], true);

        $result = $authenticator->authenticate(
            AgentCustomerAuthenticatorFixture::credential(),
            AgentCustomerAuthenticatorFixture::requestContext(),
            ['com.shopware.quote:manage', 'dev.ucp.shopping.order:manage'],
        );

        self::assertSame(self::CUSTOMER_ID, $result->getCustomer()?->getId());
    }

    public function testItIgnoresRequiredScopesWhenTheIdentityProviderCannotGrantThem(): void
    {
        $authenticator = $this->authenticator(['dev.ucp.shopping.cart:manage'], false);

        $result = $authenticator->authenticate(
            AgentCustomerAuthenticatorFixture::credential(),
            AgentCustomerAuthenticatorFixture::requestContext(),
            ['com.shopware.quote:manage', 'dev.ucp.shopping.order:manage'],
        );

        self::assertSame(self::CUSTOMER_ID, $result->getCustomer()?->getId());
    }

    public function testItFailsWhenTheSubjectIsNoLongerACustomer(): void
    {
        $resolver = AgentCustomerAuthenticatorFixture::resolver($this->customerContext(null), self::SALES_CHANNEL_ID);
        $authenticator = new AgentCustomerAuthenticator(
            $resolver,
            AgentCustomerAuthenticatorFixture::reader(AgentCustomerAuthenticatorFixture::tokenInfo()),
        );

        $this->expectException(ValidationException::class);

        $authenticator->authenticate(
            AgentCustomerAuthenticatorFixture::credential(),
            AgentCustomerAuthenticatorFixture::requestContext(),
        );
    }

    /**
     * @param list<string> $grantedScopes
     */
    private function authenticator(array $grantedScopes, bool $enforceScopes): AgentCustomerAuthenticator
    {
        return new AgentCustomerAuthenticator(
            AgentCustomerAuthenticatorFixture::resolver(
                $this->customerContext(self::CUSTOMER_ID),
                self::SALES_CHANNEL_ID,
            ),
            AgentCustomerAuthenticatorFixture::reader(AgentCustomerAuthenticatorFixture::tokenInfo(
                scopes: $grantedScopes,
            )),
            $enforceScopes,
        );
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
