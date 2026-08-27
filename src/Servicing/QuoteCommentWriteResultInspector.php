<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;

final class QuoteCommentWriteResultInspector
{
    private function __construct() {}

    public static function commentId(EntityWriteResult $result): ?string
    {
        return self::nonEmptyString($result->getPrimaryKey());
    }

    public static function quoteId(EntityWriteResult $result): ?string
    {
        return self::nonEmptyString($result->getPayload()['quoteId'] ?? null);
    }

    public static function isLiveInsert(EntityWriteResult $result): bool
    {
        return $result->getOperation() === EntityWriteResult::OPERATION_INSERT
        && self::isLiveVersion($result->getPayload()['quoteVersionId'] ?? null);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function isLiveVersion(mixed $value): bool
    {
        return !\is_string($value) || $value === '' || $value === Defaults::LIVE_VERSION;
    }
}
