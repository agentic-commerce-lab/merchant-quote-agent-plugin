<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Ucp\Quote;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteOAuthScopeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuoteOAuthScopeProvider::class)]
final class QuoteOAuthScopeProviderTest extends TestCase
{
    /**
     * The quote routes require the scope this registers; an order scope is
     * Agentic Commerce's own and must not be registered twice.
     */
    public function testItRegistersOnlyTheQuoteScopeTheRoutesRequire(): void
    {
        self::assertSame([QuoteOAuthScopeProvider::MANAGE_QUOTES], (new QuoteOAuthScopeProvider())->getScopes());
        self::assertSame('com.shopware.quote:manage', QuoteOAuthScopeProvider::MANAGE_QUOTES);
        self::assertSame('dev.ucp.shopping.order:manage', QuoteOAuthScopeProvider::MANAGE_ORDERS);
    }
}
