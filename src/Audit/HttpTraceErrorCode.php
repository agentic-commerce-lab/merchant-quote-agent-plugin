<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** Accepts only bounded machine refusal codes in always-exported metadata. */
final class HttpTraceErrorCode
{
    private function __construct() {}

    /** @param array<array-key, mixed> $body */
    public static function of(int $status, array $body): ?string
    {
        if ($status < 400) {
            return null;
        }

        $code = $body['status'] ?? $body['code'] ?? $body['error']['code'] ?? null;

        return \is_string($code) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $code) ? $code : null;
    }
}
