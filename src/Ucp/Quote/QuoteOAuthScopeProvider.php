<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

/**
 * Registers `com.shopware.quote:manage` with Agentic Commerce's OAuth scope
 * registry (1.4.0+), so that scope is listed in `scopes_supported` and can be
 * granted at consent. Before 1.4.0, Agentic Commerce rejected every scope it did
 * not define itself.
 *
 * ponytail: duck-typed against `Swag\AgenticCommerce\Ucp\Identity\AbstractUcpOAuthScopeProvider`
 * instead of extending it. This plugin has no compile-time dependency on Agentic
 * Commerce, and that registry calls `getScopes()` on whatever carries the tag.
 * If it ever adds a type check, extend the abstract class instead and add Agentic
 * Commerce as a dev dependency so mago can see it.
 *
 * The order scope is Agentic Commerce's own, so it is only named here (accepting
 * a quote places an order) and never registered.
 */
final class QuoteOAuthScopeProvider
{
    public const TAG = 'swag_agentic_commerce.ucp.oauth_scope_provider';

    /** Whether Agentic Commerce can register extension scopes: the class exists from 1.4.0. */
    public const REGISTRY_CLASS = 'Swag\\AgenticCommerce\\Ucp\\Identity\\UcpOAuthScopeRegistry';

    public const MANAGE_QUOTES = 'com.shopware.quote:manage';

    public const MANAGE_ORDERS = 'dev.ucp.shopping.order:manage';

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return [self::MANAGE_QUOTES];
    }
}
