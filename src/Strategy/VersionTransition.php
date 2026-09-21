<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * The ONE exception to "a version row never changes" (see StrategyWriteGuard).
 *
 * An `active` row stays as immutable as it was before proposals existed: a
 * decision stores a version id, and the prompt behind that id must never
 * change. A `rejected` row is terminal. Only a `proposed` row may move, once,
 * to accepted or rejected, and only these four columns may move with it --
 * `prompt`, `strategy_id` and `run_id` are refused outright, so an accepted
 * proposal is exactly the text that was evaluated.
 *
 * Split out of the guard itself so the guard's own complexity stays legible:
 * it decides WHICH rows this rule applies to, this decides WHETHER a given
 * write is the one admitted transition.
 */
final class VersionTransition
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = ['status', 'version', 'decided_at', 'updated_at'];

    private function __construct() {}

    /** @param array<string, mixed> $payload */
    public static function isAdmitted(?string $currentStatus, array $payload): bool
    {
        if ($currentStatus !== VersionStatus::Proposed->value) {
            return false;
        }

        $target = $payload['status'] ?? null;

        if ($target !== VersionStatus::Active->value && $target !== VersionStatus::Rejected->value) {
            return false;
        }

        return array_diff(array_keys($payload), self::ALLOWED_FIELDS) === [];
    }
}
