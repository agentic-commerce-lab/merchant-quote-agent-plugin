<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * One decision, reduced to what the nightly self-improvement loop is allowed
 * to carry. Task 11 harvests these from QuoteDecisionRecord for a window and
 * feeds them to two different readers with two different privacy budgets:
 *
 *  - DayPicture, which reduces a whole list of these to counts and a mean --
 *    see that class's own docblock for exactly which of the ten properties
 *    below it reads, and why the rest never reach a model.
 *  - The replay harness (Task 11), which needs `quoteId` to re-fetch the live
 *    quote and `interpretedAsks` to feed ReplayEvaluator the same structured
 *    ask the live pass saw, entirely inside this process.
 *
 * `interpretedAsks` is built from the RAW stored interpretation --
 * DecisionHarvest::harvest() reads it straight off QuoteDecisionRecord,
 * exactly the payload DecisionRecorder::recordAsk() wrote via
 * InterpretationPayload::of() -- and it must stay that way. The export's
 * stripped shape (AnonymizedDecision::asks(), which drops
 * `clarificationQuestions` and `humanReviewRequests`) looks like the more
 * privacy-conscious choice, but it can NEVER be rehydrated:
 * InterpretationHydrator::hydrate() refuses any payload missing either of
 * those two top-level keys (see its TOP_LEVEL_KEYS guard). Building this
 * property from the stripped shape would make InterpretationPayload::from()
 * return null for every decision, so every replay would count as `skipped`
 * and every run would report "0 sampled" -- silently, forever.
 * DecisionHarvestTest::testARealisticStoredInterpretedAsksPayloadRehydrates()
 * pins both halves: the raw shape rehydrates, the stripped one does not.
 *
 * This is safe because the privacy budget that motivated the strip belongs to
 * a DIFFERENT reader with a DIFFERENT risk: DayPicture, which feeds a model
 * and does not read `interpretedAsks` at all (see its own docblock -- it
 * touches only `band`, `escalationReason`, `terminalState` and the two
 * discount floats). The replay harness that DOES read this property
 * (ReplaySubjectResolver, ReplayEvaluator) never sends it anywhere: it stays
 * in-process, feeding only NegotiationDecider's band check and
 * CappedAuthority's ceiling math -- both deterministic code, never a model
 * call and never a written row.
 *
 * `strategyVersionId` and `strategyAssignmentSource` are the same raw
 * passthrough as everything else here: whatever the decision row named, even
 * null for a decision recorded before the assignment ladder shipped that
 * column. DecisionHarvest is what turns a null or unresolvable
 * `strategyVersionId` into "this decision is excluded from every strategy
 * group" -- this class carries the raw value and makes no judgement about it.
 *
 * Twelve properties, flat, because the replay harness and the administration
 * read them as a plain data carrier. The constructor groups them into three
 * small value objects instead -- DecisionClassification, DecisionDiscount,
 * DecisionExtraction -- because a flat twelve-argument constructor would trip
 * the excessive-parameter-list gate (max 5); each group is cohesive on its
 * own (see their docblocks) and this class only unpacks them onto the
 * properties that are its real contract.
 *
 * @mago-expect lint:too-many-properties
 * The gate fires above 10; these ARE the fields the replay harness and the
 * administration read off one decision (see ImprovementRun's own docblock
 * for the same trade-off on its table-column properties).
 */
final readonly class HarvestedDecision
{
    public string $decisionId;

    public string $quoteId;

    public ?string $band;

    public ?string $outcome;

    public ?string $escalationReason;

    public ?string $terminalState;

    public ?float $discountPercentGranted;

    public ?float $maxDiscountPercent;

    /** @var array<string, mixed>|null */
    public ?array $interpretedAsks;

    public ?string $extractPromptHash;

    public ?string $strategyVersionId;

    public ?string $strategyAssignmentSource;

    public function __construct(
        string $decisionId,
        string $quoteId,
        DecisionClassification $classification,
        DecisionDiscount $discount,
        DecisionExtraction $extraction,
    ) {
        $this->decisionId = $decisionId;
        $this->quoteId = $quoteId;
        $this->band = $classification->band;
        $this->outcome = $classification->outcome;
        $this->escalationReason = $classification->escalationReason;
        $this->terminalState = $classification->terminalState;
        $this->discountPercentGranted = $discount->discountPercentGranted;
        $this->maxDiscountPercent = $discount->maxDiscountPercent;
        $this->interpretedAsks = $extraction->interpretedAsks;
        $this->extractPromptHash = $extraction->extractPromptHash;
        $this->strategyVersionId = $extraction->strategyVersionId;
        $this->strategyAssignmentSource = $extraction->strategyAssignmentSource;
    }
}
