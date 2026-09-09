<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * Admits `null` into a schema node's `enum` list when the node's type permits
 * it. Split out of ResponseFormatFactory, which owns the recursion, to keep
 * that class inside the complexity gate.
 *
 * `enum` is an absolute whitelist in JSON Schema regardless of `type`. When
 * Symfony AI generates a schema for a nullable backed-enum property, it
 * derives the nullable `type` correctly but never adds `null` to the `enum`
 * list beside it. Left alone, a provider enforcing strict schema validation
 * cannot accept that property's null value at all — the property is required,
 * and every value in the enum names something. A nullable property that cannot
 * be null wastes options and burns request rounds: see ResponseFormatFactory
 * for why this class exists in the first place.
 */
final class AdmitNullInEnum
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    public static function rewrite(array $node): array
    {
        $enum = $node['enum'] ?? null;
        $type = $node['type'] ?? null;
        $permitsNull = $type === 'null' || \is_array($type) && \in_array('null', $type, strict: true);

        if (\is_array($enum) && $permitsNull && !\in_array(null, $enum, strict: true)) {
            $enum[] = null;
            $node['enum'] = $enum;
        }

        return $node;
    }
}
