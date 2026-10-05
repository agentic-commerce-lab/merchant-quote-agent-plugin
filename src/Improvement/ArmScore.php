<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/** Aggregate outcome of replaying one arm (control or candidate) across a sample. */
final readonly class ArmScore
{
    public int $modelRefusals;

    public int $failures;

    public int $unmeasured;

    /**
     * @param array{modelRefusals: int, failures: int, unmeasured: int} $anomalies
     *     Arms that did not contribute a clean measurement, kept apart so
     *     each stays legible instead of hiding inside the escalation rate
     *     or the mean.
     */
    public function __construct(
        public int $sampled,
        public float $escalationRate,
        public ?float $meanGrantedPercent,
        array $anomalies,
    ) {
        $this->modelRefusals = $anomalies['modelRefusals'];
        $this->failures = $anomalies['failures'];
        $this->unmeasured = $anomalies['unmeasured'];
    }
}
