<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin;

use Shopware\Core\Framework\Plugin;

/**
 * Merchant-side quote agent: reacts to B2B quote lifecycle events, negotiates
 * within merchant-defined limits, and escalates anything outside them.
 *
 * Requires SwagCommercial (B2B quote management) and the Agentic Commerce
 * plugin — the latter is what imports the UCP SDK's routes into Shopware, so
 * without it there is no UCP surface for this plugin to extend.
 *
 * Services are loaded from Resources/config/services.php by Bundle::build().
 *
 * @mago-expect analysis:missing-constructor
 * Symfony's Bundle declares $container/$name as typed properties without
 * defaults and initialises them outside a constructor (setContainer, getName).
 * Shopware's Plugin sets $path via its own constructor. Nothing for us to add.
 */
class MerchantQuoteAgentPlugin extends Plugin
{
    public const CONTEXT_STATE_AGENT_SERVICING = 'merchant_quote_agent_servicing';
    public const LAST_AGENT_COMMENT_ID = 'quote_agent_last_comment_id';
}
