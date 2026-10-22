<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/** Aggregates a list of replayed arms into a single ArmScore. */
final class ReplayScore
{
    private function __construct() {}

    /** @param list<ReplayArm> $arms */
    public static function of(array $arms): ArmScore
    {
        $counted = array_values(array_filter($arms, static fn(ReplayArm $arm): bool => !$arm->failed));
        $sampled = count($counted);

        $escalations = array_filter($counted, static fn(ReplayArm $arm): bool => $arm->escalated);
        $grantedPercents = array_filter(
            array_map(static fn(ReplayArm $arm): ?float => $arm->grantedPercent, $counted),
            static fn(?float $percent): bool => $percent !== null,
        );
        $unmeasured = array_filter(
            $counted,
            static fn(ReplayArm $arm): bool => !$arm->escalated && $arm->grantedPercent === null,
        );

        return new ArmScore(
            sampled: $sampled,
            escalationRate: $sampled === 0 ? 0.0 : count($escalations) / $sampled,
            meanGrantedPercent: count($grantedPercents) === 0
                ? null
                : array_sum($grantedPercents) / count($grantedPercents),
            anomalies: [
                'modelRefusals' => count(array_filter($counted, static fn(ReplayArm $arm): bool => $arm->modelRefused)),
                'failures' => count($arms) - $sampled,
                'unmeasured' => count($unmeasured),
            ],
        );
    }
}
