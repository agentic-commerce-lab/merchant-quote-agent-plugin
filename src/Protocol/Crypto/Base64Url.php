<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/** base64url (RFC 4648 §5) without padding — the only encoding JWS and A2CN use. */
final class Base64Url
{
    private function __construct() {}

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Returns '' for input that is not valid base64url, so callers fail closed. */
    public static function decode(string $value): string
    {
        $padded = strtr($value, '-_', '+/') . str_repeat('=', (4 - (\strlen($value) % 4)) % 4);
        $decoded = base64_decode($padded, strict: true);

        return $decoded === false ? '' : $decoded;
    }
}
