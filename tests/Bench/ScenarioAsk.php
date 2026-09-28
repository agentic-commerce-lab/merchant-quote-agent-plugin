<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * Renders `{unit*<factor>}` in a scenario's ask as factor x the quote's own
 * unit price, two decimals. The same rule as scripts/eval/scenarios.mjs
 * render(), for the in-process bench: an absolute price only ever made sense
 * against one product (Ruling A14).
 */
final class ScenarioAsk
{
    private const PLACEHOLDER = '/\{unit\*([0-9]+(?:\.[0-9]+)?)\}/';

    private function __construct() {}

    public static function render(string $text, float $unitPrice): string
    {
        return (
            preg_replace_callback(
                self::PLACEHOLDER,
                static fn(array $match): string => number_format(round((float) $match[1] * $unitPrice, 2), 2, '.', ''),
                $text,
            ) ?? $text
        );
    }
}
