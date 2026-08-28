<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;

/**
 * Everything the agent needs to service one sales channel, already validated.
 *
 * There is deliberately no `enabled` flag. QuoteAgentSettingsFactory returns
 * null for a disabled channel and throws for a misconfigured one, so an
 * instance of this class always means "enabled and valid" and `$policy` is
 * never nullable — #18 cannot be handed settings it must re-check.
 *
 * No Shopware in it. The reader touches SystemConfigService; this does not,
 * so the negotiation engine stays framework-free.
 */
final readonly class QuoteAgentSettings
{
    public function __construct(
        public NegotiationPolicy $policy,
        public bool $rulesOnly,
        public ?ModelAccess $llm,
        public ?string $strategyPrompt,
    ) {}
}
