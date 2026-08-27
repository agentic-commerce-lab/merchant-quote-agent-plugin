<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;

final class QuoteCommentFilter
{
    private const SERVICEABLE_STATES = ['open', 'in_review', 'change_requested'];

    private function __construct() {}

    /** @param array<string, mixed> $payload */
    public static function shouldSkipComment(array $payload, QuoteSnapshot $snapshot): bool
    {
        if (
            \array_key_exists('customerId', $payload) && $payload['customerId'] !== null
            || \array_key_exists('employeeId', $payload) && $payload['employeeId'] !== null
        ) {
            return false;
        }

        if (!\array_key_exists('comment', $payload) || !\is_string($payload['comment'])) {
            return false;
        }

        $customFields = $snapshot->lifecycle->customFields;
        if (!\array_key_exists(MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT, $customFields)) {
            return false;
        }

        return (
            \is_string($customFields[MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT])
            && $customFields[MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT] === $payload['comment']
        );
    }

    public static function isServiceableState(string $state): bool
    {
        return \in_array($state, self::SERVICEABLE_STATES, strict: true);
    }
}
