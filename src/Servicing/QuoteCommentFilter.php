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
            \array_key_exists('createdById', $payload) && $payload['createdById'] !== null
            || \array_key_exists('customerId', $payload) && $payload['customerId'] !== null
            || \array_key_exists('employeeId', $payload) && $payload['employeeId'] !== null
        ) {
            return false;
        }

        if (!\is_string($payload['comment'] ?? null)) {
            return false;
        }

        $comment = $payload['comment'];
        $customFields = $snapshot->lifecycle->customFields;

        return self::matchesPersistedAgentComment(
            $customFields[MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_TEXT] ?? null,
            $comment,
        );
    }

    public static function isServiceableState(string $state): bool
    {
        return \in_array($state, self::SERVICEABLE_STATES, strict: true);
    }

    private static function matchesPersistedAgentComment(mixed $persistedComment, string $comment): bool
    {
        return \is_string($persistedComment) && $persistedComment === $comment;
    }
}
