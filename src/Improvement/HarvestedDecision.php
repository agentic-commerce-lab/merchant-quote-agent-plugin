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
 * `interpretedAsks` is deliberately the STRUCTURED shape only --
 * AnonymizedDecision::asks() already strips `clarificationQuestions` and
 * `humanReviewRequests` off the same data for the same reason (those two
 * lists are sentences the extraction model wrote, not typed asks) -- this
 * class must be built from that same stripped shape, never from the raw
 * interpretation.
 *
 * Ten properties, flat, because Task 11 reads them as a plain data carrier.
 * The constructor groups them into three small value objects instead --
 * DecisionClassification, DecisionDiscount, DecisionExtraction -- because a
 * flat ten-argument constructor would trip the excessive-parameter-list gate
 * (max 5); each group is cohesive on its own (see their docblocks) and this
 * class only unpacks them onto the properties that are its real contract.
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
    }
}
