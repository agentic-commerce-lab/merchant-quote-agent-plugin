<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * The harness's own self-check.
 *
 * The control arm replays the LIVE prompt against decisions whose real
 * outcome we already know. If it lands far from what the day actually
 * recorded, something upstream is wrong -- a drifted quote, a half-rehydrated
 * interpretation, a provider behaving differently -- and the candidate's delta
 * is a number computed on rubble.
 *
 * The run still writes the proposal and its numbers; it flags them instead of
 * suppressing them, because hiding the evidence of a bad night is how a loop
 * like this stops being auditable.
 *
 * 15 points is a judgement call, not a measurement: below it the arithmetic of
 * a small sample (one decision in twenty is five points) explains the gap, and
 * above it the inputs do.
 */
final class ControlDivergence
{
    public const MAX_POINTS = 15.0;

    private function __construct() {}

    /** Both rates as percentages, 0-100. */
    public static function diverged(float $controlRate, float $recordedRate): bool
    {
        return abs($controlRate - $recordedRate) > self::MAX_POINTS;
    }
}
