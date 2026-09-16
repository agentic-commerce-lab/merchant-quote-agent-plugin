<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * Whether an escalation may post its notice into the buyer's quote
 * conversation — and nothing else.
 *
 * Separate from QuoteAgentSettingsSource because it must be answerable when
 * that one cannot answer. A NotConfigured escalation is raised from
 * `forSalesChannel()` throwing InvalidQuoteAgentConfiguration; asking the same
 * source whether to tell the buyer throws again, and #140 is what that cost:
 * every misconfigured shop escalated in total silence, because the second
 * throw was read as "the merchant asked for silence".
 *
 * This toggle depends on none of what the factory validates: not the LLM
 * credentials, not the policy numbers. So it is read raw, one key, no
 * validation, and it cannot fail.
 */
interface BuyerNotificationPreference
{
    public function notifyBuyerOnEscalation(?string $salesChannelId): bool;
}
