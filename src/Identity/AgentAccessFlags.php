<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Whether a sales channel currently admits any agent that presents a fetchable,
 * signed profile.
 *
 * Deliberately not part of {@see \MerchantQuoteAgentPlugin\Config\QuoteAgentSettings}:
 * that is the negotiation configuration a merchant edits, and this is a security
 * switch set only by `bin/console merchant-quote-agent:allow-any-agent`. It has
 * no `config.xml` entry for the same reason — that file would render it in the
 * plugin's settings form.
 *
 * A null sales channel means the request could not be attributed to one, which
 * is never a reason to widen anything.
 */
final readonly class AgentAccessFlags
{
    public const ALLOW_ANY_AGENT_KEY = 'MerchantQuoteAgentPlugin.config.allowAnyAgent';

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {}

    public function allowAnyAgent(?string $salesChannelId): bool
    {
        if ($salesChannelId === null) {
            return false;
        }

        return true === $this->systemConfig->get(self::ALLOW_ANY_AGENT_KEY, $salesChannelId);
    }
}
