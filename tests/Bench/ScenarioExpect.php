<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;

/**
 * The part of a scenario's `expect` block PHP reads: round 1's outcome
 * (spec 2026-09-28-claude-code-evals-design). Keyed to NegotiationOutcome's
 * backing values -- what the decision row stores -- because the `expectedBand`
 * this replaces said auto/clarify/escalate, which no enum in src/ writes.
 *
 * The rest of `expect` (maxEscalations, order, judge) is read and validated
 * by the UCP buyer, scripts/eval/scenarios.mjs.
 */
final readonly class ScenarioExpect
{
    /** @param list<string> $firstOutcome [] asserts nothing (inline test scenarios) */
    public function __construct(
        public array $firstOutcome = [],
    ) {}

    /** @param array<string, mixed> $data the whole scenario */
    public static function from(array $data): self
    {
        if (\array_key_exists('expectedBand', $data)) {
            throw new \InvalidArgumentException(
                'Scenario field "expectedBand" was replaced by "expect.firstOutcome" (NegotiationOutcome values).',
            );
        }

        $expect = $data['expect'] ?? [];
        $outcomes = \is_array($expect) ? $expect['firstOutcome'] ?? [] : null;
        if (!\is_array($outcomes) || !array_is_list($outcomes)) {
            throw new \InvalidArgumentException('Scenario field "expect.firstOutcome" must be a list.');
        }

        $valid = array_map(static fn(NegotiationOutcome $case): string => $case->value, NegotiationOutcome::cases());
        foreach ($outcomes as $outcome) {
            if (!\is_string($outcome) || !\in_array($outcome, $valid, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Scenario field "expect.firstOutcome" names "%s", which is not a NegotiationOutcome value (%s).',
                    \is_string($outcome) ? $outcome : get_debug_type($outcome),
                    implode(', ', $valid),
                ));
            }
        }

        /** @var list<string> $outcomes */
        return new self($outcomes);
    }
}
