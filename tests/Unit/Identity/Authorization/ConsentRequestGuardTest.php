<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Identity\Authorization;

use MerchantQuoteAgentPlugin\Identity\Authorization\ConsentRequestGuard;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorization;
use MerchantQuoteAgentPlugin\Identity\Authorization\PendingAuthorizationStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

#[CoversClass(ConsentRequestGuard::class)]
final class ConsentRequestGuardTest extends TestCase
{
    private const SALES_CHANNEL_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';

    public function testAnUnknownHandleIsRefused(): void
    {
        $guard = new ConsentRequestGuard($this->store(null));

        self::assertNull($guard->verifiedPending('unknown-handle', $this->context(
            self::SALES_CHANNEL_ID,
            signedIn: true,
        )));
    }

    public function testAGuestSessionIsRefusedEvenForAKnownHandle(): void
    {
        $guard = new ConsentRequestGuard($this->store($this->pending(self::SALES_CHANNEL_ID)));

        self::assertNull($guard->verifiedPending('handle', $this->context(self::SALES_CHANNEL_ID, signedIn: false)));
    }

    public function testACustomerSignedInOnAnotherSalesChannelIsRefused(): void
    {
        $guard = new ConsentRequestGuard($this->store($this->pending(self::SALES_CHANNEL_ID)));

        self::assertNull($guard->verifiedPending('handle', $this->context(
            '0191d3d0a0b071bd9c1a0d9d1a3f9fff',
            signedIn: true,
        )));
    }

    public function testAMatchingSignedInCustomerOnTheRegisteredChannelIsAccepted(): void
    {
        $pending = $this->pending(self::SALES_CHANNEL_ID);
        $guard = new ConsentRequestGuard($this->store($pending));

        self::assertSame($pending, $guard->verifiedPending('handle', $this->context(
            self::SALES_CHANNEL_ID,
            signedIn: true,
        )));
    }

    /**
     * matchesChannel() is exposed on its own so the GET route can bind the
     * channel too, without re-`find()`-ing or repeating the customer check.
     */
    public function testMatchesChannelAcceptsTheRegisteredChannel(): void
    {
        $guard = new ConsentRequestGuard($this->store(null));
        $pending = $this->pending(self::SALES_CHANNEL_ID);

        self::assertTrue($guard->matchesChannel($pending, $this->context(self::SALES_CHANNEL_ID, signedIn: true)));
    }

    public function testMatchesChannelRefusesAnyOtherChannel(): void
    {
        $guard = new ConsentRequestGuard($this->store(null));
        $pending = $this->pending(self::SALES_CHANNEL_ID);

        self::assertFalse($guard->matchesChannel($pending, $this->context(
            '0191d3d0a0b071bd9c1a0d9d1a3f9fff',
            signedIn: true,
        )));
    }

    private function pending(string $salesChannelId): PendingAuthorization
    {
        return new PendingAuthorization(
            $salesChannelId,
            'https://agent.example/.well-known/ucp',
            [],
            'https://agent.example/callback',
            'dev.ucp.shopping.order:read',
            'state-value',
            'challenge-value',
            'S256',
        );
    }

    private function store(?PendingAuthorization $found): PendingAuthorizationStoreInterface
    {
        return new class($found) implements PendingAuthorizationStoreInterface {
            public function __construct(
                private readonly ?PendingAuthorization $found,
            ) {}

            public function store(PendingAuthorization $pending, int $ttlSeconds): string
            {
                return 'handle-value';
            }

            public function find(#[\SensitiveParameter] string $handle): ?PendingAuthorization
            {
                return $this->found;
            }

            public function consume(#[\SensitiveParameter] string $handle): ?PendingAuthorization
            {
                return $this->found;
            }
        };
    }

    private function context(string $salesChannelId, bool $signedIn): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($signedIn ? new CustomerEntity() : null);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);

        return $context;
    }
}
