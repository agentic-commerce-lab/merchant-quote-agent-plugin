<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use PHPUnit\Framework\TestCase;

final class SessionQuoteLocatorTest extends TestCase
{
    public function testItPrefersTheMirrorWhichIsIndexed(): void
    {
        $store = new InMemoryActStore();
        // Seed one act so the mirror knows the session; see InMemoryActStore
        // for the exact seeding helper it exposes.
        $store->seedSession('session-1', 'quote-1');

        $locator = new SessionQuoteLocator($store, null);

        self::assertSame('quote-1', $locator->quoteIdFor('session-1'));
    }

    public function testItReturnsNullWhenNothingKnowsTheSession(): void
    {
        self::assertNull((new SessionQuoteLocator(new InMemoryActStore(), null))->quoteIdFor('session-unknown'));
    }
}
