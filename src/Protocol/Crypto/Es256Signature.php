<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Crypto;

/**
 * ECDSA P-256 signatures in the two shapes that matter here.
 *
 * `openssl_sign`/`openssl_verify` speak DER `SEQUENCE{INTEGER r, INTEGER s}`;
 * a JWS ES256 signature is the fixed-width concatenation `R‖S` (32 bytes each,
 * RFC 7518 §3.4). Everything else in the module is built on this conversion.
 */
final class Es256Signature
{
    private const COORDINATE_BYTES = 32;

    private function __construct() {}

    /** @throws MalformedSignature */
    public static function toRaw(string $der): string
    {
        $offset = 0;
        self::expect($der, $offset, "\x30");
        $length = self::byteAt($der, $offset);
        if ($length > 0x80) {
            // Long form: the low nibble counts the length-of-length bytes.
            $offset += $length - 0x80;
        }

        $parts = [];
        for ($index = 0; $index < 2; ++$index) {
            self::expect($der, $offset, "\x02");
            $size = self::byteAt($der, $offset);
            $value = ltrim(substr($der, $offset, $size), "\x00");
            $offset += $size;
            if (\strlen($value) > self::COORDINATE_BYTES) {
                throw new MalformedSignature('ECDSA integer wider than the P-256 coordinate size.');
            }
            $parts[] = str_pad($value, self::COORDINATE_BYTES, "\x00", \STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    /** @throws MalformedSignature */
    public static function toDer(string $raw): string
    {
        if (\strlen($raw) !== (self::COORDINATE_BYTES * 2)) {
            throw new MalformedSignature(\sprintf('A raw ES256 signature is 64 bytes, got %d.', \strlen($raw)));
        }

        $body =
            self::integer(substr($raw, 0, self::COORDINATE_BYTES))
            . self::integer(substr($raw, self::COORDINATE_BYTES));

        return "\x30" . \chr(\strlen($body)) . $body;
    }

    private static function integer(string $value): string
    {
        $trimmed = ltrim($value, "\x00");
        if ($trimmed === '') {
            $trimmed = "\x00";
        }
        // DER integers are signed: a leading bit set means prepend a zero byte.
        if ((\ord($trimmed[0]) & 0x80) !== 0) {
            $trimmed = "\x00" . $trimmed;
        }

        return "\x02" . \chr(\strlen($trimmed)) . $trimmed;
    }

    /** @throws MalformedSignature */
    private static function expect(string $der, int &$offset, string $tag): void
    {
        if (($der[$offset] ?? '') !== $tag) {
            throw new MalformedSignature('Unexpected DER tag in an ECDSA signature.');
        }
        ++$offset;
    }

    /** @throws MalformedSignature */
    private static function byteAt(string $der, int &$offset): int
    {
        $byte = $der[$offset] ?? '';
        if ($byte === '') {
            throw new MalformedSignature('Truncated DER ECDSA signature.');
        }
        ++$offset;

        return \ord($byte);
    }
}
