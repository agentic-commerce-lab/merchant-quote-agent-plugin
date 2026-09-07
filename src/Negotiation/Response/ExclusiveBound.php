<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * One exclusive numeric bound on one schema node, moved from JSON Schema
 * draft-04 to 2020-12. Split out of ResponseFormatFactory, which owns the
 * recursion, to keep that class inside the complexity gate.
 *
 * draft-04 wrote an exclusive bound as a boolean flag beside an inclusive one
 * (`minimum: 0, exclusiveMinimum: true`); 2020-12 makes the exclusive keyword
 * the bound itself (`exclusiveMinimum: 0`). See ResponseFormatFactory for why
 * this rewrite has to happen at all.
 */
final class ExclusiveBound
{
    private function __construct() {}

    /**
     * A boolean flag is draft-04 whichever way it points, so it always goes.
     * Only a `true` beside a real number has a 2020-12 form to become; a
     * `true` beside nothing usable loses the bound, which beats failing the
     * whole request over a limit the model was never going to enforce.
     *
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    public static function rewrite(array $node, string $exclusive, string $inclusive): array
    {
        $flag = $node[$exclusive] ?? null;

        if (!\is_bool($flag)) {
            return $node;
        }

        unset($node[$exclusive]);
        $bound = $node[$inclusive] ?? null;

        if ($flag && (\is_int($bound) || \is_float($bound))) {
            $node[$exclusive] = $bound;
            unset($node[$inclusive]);
        }

        return $node;
    }
}
