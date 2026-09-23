<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * Turns whatever a recording site hands over into what a JSON column can hold,
 * without ever throwing.
 *
 * Not InterpretationPayload: that one throws on purpose, because a decision
 * column that silently lost its value would be worse than a failed write. A
 * trace is the opposite trade: it is recorded from inside a running pass,
 * from values nobody validated (a provider's raw error body, a quote's custom
 * fields), and a trace that is partly substituted beats a pass that fails.
 *
 * The cap is a trust-boundary control, not tidiness (spec §1): the buyer's
 * comment reaches the prompt verbatim and has no length limit, and PR 3 puts
 * request bodies from any storefront caller in here.
 */
final class TracePayload
{
    public const MAX_STRING_BYTES = 65_536;

    private function __construct() {}

    /**
     * Keeps a zero fraction (950.0 stays a float), as the DAL's own
     * Json::encode does. The catch is not redundant with the flags:
     * json_encode still propagates what a JsonSerializable throws, and an
     * unusable value records as `[]` rather than failing the pass.
     *
     * @return array<array-key, mixed>
     */
    public static function of(array|object $value): array
    {
        try {
            $encoded = json_encode(
                $value,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            );
            $decoded = \is_string($encoded) ? json_decode($encoded, true) : null;
        } catch (\Throwable) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array{0: array<array-key, mixed>, 1: list<string>} the capped value, and the dotted path of every cut string
     */
    public static function capped(array $value, string $path = ''): array
    {
        $cut = [];

        foreach ($value as $key => $leaf) {
            $at = $path === '' ? (string) $key : $path . '.' . $key;

            if (\is_array($leaf)) {
                [$value[$key], $inner] = self::capped($leaf, $at);
                $cut = [...$cut, ...$inner];
            } elseif (\is_string($leaf) && \strlen($leaf) > self::MAX_STRING_BYTES) {
                $value[$key] = mb_strcut($leaf, 0, self::MAX_STRING_BYTES, 'UTF-8');
                $cut[] = $at;
            }
        }

        return [$value, $cut];
    }
}
