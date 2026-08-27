<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;

final class QuoteCommentFilter
{
    private function __construct() {}

    /** @param array<string, mixed> $payload */
    public static function shouldSkipComment(array $payload, ?string $commentId, QuoteSnapshot $snapshot): bool
    {
        if (
            \array_key_exists('createdById', $payload) && $payload['createdById'] !== null
            || \array_key_exists('customerId', $payload) && $payload['customerId'] !== null
            || \array_key_exists('employeeId', $payload) && $payload['employeeId'] !== null
        ) {
            return false;
        }

        if ($commentId === null) {
            return false;
        }

        $customFields = $snapshot->lifecycle->customFields;

        return self::matchesPersistedAgentCommentId(
            $customFields[MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID] ?? null,
            $commentId,
        );
    }

    public static function isServiceableState(string $state): bool
    {
        return QuoteServicingStates::isServiceable($state);
    }

    private static function matchesPersistedAgentCommentId(mixed $persistedCommentId, string $commentId): bool
    {
        return \is_string($persistedCommentId) && $persistedCommentId === $commentId;
    }
}
