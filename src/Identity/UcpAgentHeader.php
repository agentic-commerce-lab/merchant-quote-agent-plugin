<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

/**
 * Reads the profile URI an agent announces in its `UCP-Agent` header.
 *
 * Two places here need the host *before* the SDK builds its request context, to decide whether a sales channel with the allow-any-agent flag should admit it: the runtime configuration resolver decorator and the profile-fetch validator factory.
 */
final class UcpAgentHeader
{
    public const NAME = 'UCP-Agent';

    /**
     * Host of the announced profile URI, or null when the header is absent or
     * carries nothing usable. Deliberately total: a caller uses this to *widen*
     * an allowlist, so an unparseable header must simply widen nothing.
     */
    public static function profileHost(?string $header): ?string
    {
        if (null === $header || '' === trim($header)) {
            return null;
        }

        $matches = [];
        if (1 !== preg_match('/profile="([^"]+)"/', $header, $matches) || !array_key_exists(1, $matches)) {
            return null;
        }

        $host = parse_url(trim($matches[1]), \PHP_URL_HOST);
        if (!\is_string($host)) {
            return null;
        }

        return rtrim(strtolower($host), characters: '.');
    }

    /**
     * @param array<string, string> $headers
     */
    public static function profileHostFromHeaders(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (0 === strcasecmp($name, self::NAME)) {
                return self::profileHost($value);
            }
        }

        return null;
    }
}
