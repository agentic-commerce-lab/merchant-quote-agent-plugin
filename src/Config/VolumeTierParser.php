<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * `minQty:discountPercent`, one per line. A textarea because `config.xml`
 * cannot express a repeatable field.
 *
 * A line that does not parse is a failure, never a skipped line: a silently
 * dropped tier changes the discount ladder while looking like it applied,
 * which is the class of silent behaviour change issue #5 exists to remove.
 * The message carries the 1-based line number because "invalid volume tiers"
 * is not something a merchant can act on.
 */
final class VolumeTierParser
{
    private function __construct() {}

    /**
     * @return list<array{minQty: int, discountPercent: float}>
     *
     * @throws \UnexpectedValueException when any non-blank line is not `<int>:<number>`
     */
    public static function parse(string $text): array
    {
        $tiers = [];
        // preg_split returns array|false, never null — `?? []` was no guard at
        // all. false only on a broken pattern, but an unguarded false here is
        // an iterator crash rather than an empty tier list.
        $lines = preg_split('/\R/', $text);

        foreach ($lines === false ? [] : $lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            $tiers[] = self::tier($trimmed, $index + 1);
        }

        return $tiers;
    }

    /**
     * @return array{minQty: int, discountPercent: float}
     *
     * @throws \UnexpectedValueException
     */
    private static function tier(string $line, int $lineNumber): array
    {
        $parts = array_map(trim(...), explode(':', $line));

        if (\count($parts) !== 2) {
            throw new \UnexpectedValueException(sprintf(
                'Volume tiers, line %d: expected "minQty:discountPercent" such as "10:5", got "%s".',
                $lineNumber,
                $line,
            ));
        }

        $minQty = filter_var($parts[0], FILTER_VALIDATE_INT);
        if ($minQty === false || !is_numeric($parts[1])) {
            throw new \UnexpectedValueException(sprintf(
                'Volume tiers, line %d: expected "minQty:discountPercent" such as "10:5", got "%s".',
                $lineNumber,
                $line,
            ));
        }

        return ['minQty' => $minQty, 'discountPercent' => (float) $parts[1]];
    }
}
