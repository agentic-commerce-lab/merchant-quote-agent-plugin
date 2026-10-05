<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * A period's negotiation decisions, reduced to AGGREGATES ONLY, for a model
 * that will never see a buyer's message. This is the one input ImprovementJudge
 * hands to the model besides the current strategy prompt, and it is the whole
 * privacy boundary of the nightly loop -- see ImprovementJudge's own docblock
 * for what the system prompt does with it.
 *
 * describe() reads exactly five of HarvestedDecision's ten properties: `band`,
 * `escalationReason` and `terminalState` (three closed vocabularies -- an
 * enum's wire value, never a sentence) and `discountPercentGranted` /
 * `maxDiscountPercent` (two numbers). It never reads `decisionId`, `quoteId`,
 * `outcome`, `interpretedAsks` or `extractPromptHash` -- `outcome` is left out
 * because `band` already carries the shape of the pass and the brief did not
 * ask for a second tally saying the same thing; the other four exist on
 * HarvestedDecision only for the replay harness (Task 11) to re-run a decision
 * inside this process, and none of them is prose a buyer could have written,
 * but they are shop-identifying or free-form enough that this class does not
 * take the risk of touching them at all.
 *
 * `interpretedAsks` in particular is the RAW stored interpretation --
 * exactly what DecisionRecorder::recordAsk() wrote via InterpretationPayload::of(),
 * never the shape AnonymizedDecision::asks() exports after stripping
 * `clarificationQuestions` and `humanReviewRequests` (see HarvestedDecision's
 * own docblock for why the raw shape is the one this class is built from) --
 * and this class still leaves it untouched, because a structured ask can
 * itself carry a buyer-typed string (an addProducts sku or a lineChanges
 * description free field). Aggregating it would mean deciding, per field,
 * whether that field is safe; not aggregating it at all means that question
 * never has to be answered correctly under time pressure.
 */
final readonly class DayPicture
{
    /**
     * @param array<string, int> $byBand
     * @param array<string, int> $byEscalationReason
     * @param array<string, int> $byTerminalState
     */
    private function __construct(
        private int $total,
        private array $byBand,
        private array $byEscalationReason,
        private array $byTerminalState,
        private DiscountSpread $discount,
    ) {}

    /** @param list<HarvestedDecision> $decisions */
    public static function of(array $decisions): self
    {
        return new self(
            total: \count($decisions),
            byBand: self::tally($decisions, static fn(HarvestedDecision $d): ?string => $d->band),
            byEscalationReason: self::tally(
                $decisions,
                static fn(HarvestedDecision $d): ?string => $d->escalationReason,
            ),
            byTerminalState: self::tally($decisions, static fn(HarvestedDecision $d): ?string => $d->terminalState),
            discount: DiscountSpread::of($decisions),
        );
    }

    public function describe(): string
    {
        $lines = [
            \sprintf('%d decisions in the window.', $this->total),
            self::tallyLine('By band', $this->byBand),
            self::tallyLine('By escalation reason', $this->byEscalationReason),
            self::tallyLine('By terminal state', $this->byTerminalState),
            \sprintf(
                'Authorizer rejected the proposed offer %d time(s).',
                $this->byEscalationReason['proposal_rejected'] ?? 0,
            ),
            $this->discount->describe(),
        ];

        return implode("\n", array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /**
     * @param list<HarvestedDecision>         $decisions
     * @param \Closure(HarvestedDecision): ?string $pick
     *
     * @return array<string, int>
     */
    private static function tally(array $decisions, \Closure $pick): array
    {
        $counts = [];

        foreach ($decisions as $decision) {
            $key = $pick($decision) ?? 'none';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param array<string, int> $counts */
    private static function tallyLine(string $label, array $counts): string
    {
        if ($counts === []) {
            return '';
        }

        ksort($counts);
        $parts = [];

        foreach ($counts as $key => $count) {
            $parts[] = "{$key}={$count}";
        }

        return "{$label}: " . implode(', ', $parts) . '.';
    }
}
