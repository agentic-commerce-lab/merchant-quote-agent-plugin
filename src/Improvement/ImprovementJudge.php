<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteContent;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteIdentity;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTotals;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;

/**
 * One model call over a whole period, reading DayPicture's aggregates and the
 * merchant's current strategy prompt, and proposing findings plus candidate
 * replacement prompts. Uses ModelPlatform::object() -- the same structured-
 * output path OfferProposer uses for the negotiate call -- so the schema
 * comes from JudgeAnswer via ResponseFormatFactory and nothing here parses
 * JSON by hand.
 *
 * The system prompt tells the model three things: a candidate replaces the
 * merchant's STRATEGY SECTION only (PromptComposer::negotiate() still owns
 * the base instructions); bands and discount caps are not the judge's
 * business; and a candidate that asks for more authority will be REFUSED by
 * OfferAuthorizer when it is replayed and will score worse for it. That third
 * point is a HINT to the model, never a control -- the control is
 * OfferAuthorizer itself, running inside ReplayEvaluator during evaluation.
 * A model that ignores the hint produces a candidate that loses on the
 * numbers, not one that silently gets more authority.
 *
 * Returns null -- a run with findings but no proposal, rather than a failed
 * run -- on a model outage (ModelPlatform::object() folds a serializer
 * failure into the same ModelUnavailable), on an empty candidate list, and
 * when every candidate's prompt is blank. A candidate with a blank prompt is
 * dropped individually first; only a list that is empty AFTER that drop
 * counts as "no usable answer".
 *
 * $platform and $recorder are the run's PRIVATE pair (`merchant_quote_agent.
 * improvement.model_platform` / `...recorder` in services.php), the same ones
 * ReplayHarness's replay uses -- never the container's shared ModelPlatform::class,
 * whose recorder has no open draft outside a live servicing pass and would
 * silently drop this call's tokens (recordModelCall() no-ops on a null draft).
 * begin()/finish() bracket the ONE call the same way ReplayEvaluator brackets
 * each replayed decision -- see that class's own docblock -- with a nominal
 * QuoteSnapshot rather than a real one: there is no quote behind a period-level
 * judge call, and TallyingDecisionWriter reads only the token counts, so a
 * nominal snapshot is honest rather than a guess dressed up as data.
 * RecorderOwnershipTest is the scan that keeps this the only other class
 * allowed to open one.
 *
 * ImprovementRunner reads the resulting token delta off ReplayHarness's own
 * tally (ReplayHarness::tokensSoFar()) rather than this class returning it:
 * JudgeAnswer IS the model's JSON response schema (see its own docblock), so
 * it may carry nothing the model itself did not answer with.
 */
final readonly class ImprovementJudge
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are reviewing how a merchant's automated quote-negotiation agent
        behaved over a recent period, from aggregate statistics only -- you are
        never shown a buyer's message, a quote number or any other free text.

        Your job has two parts:
        1. Name any patterns you can see in the statistics (a band escalating
           too often, discounts clustering at the cap, a terminal state that
           looks wrong for its band).
        2. Propose candidate replacement texts for the merchant's STRATEGY
           SECTION of the negotiation prompt -- the part that tunes tone and
           posture. You are NOT proposing changes to the merchant's discount
           bands or value caps; those are not yours to change, and no
           candidate you write can move them.

        A candidate that asks the agent for more discount authority than the
        merchant configured will be REFUSED at evaluation time by the same
        authorization check production uses, regardless of what your candidate
        says. Asking for more authority does not test well -- it will simply
        score worse than a candidate that works within the existing bands.
        PROMPT;

    public function __construct(
        private ModelPlatform $platform,
        private DecisionRecorder $recorder,
    ) {}

    public function assess(ModelAccess $llm, DayPicture $picture, string $currentPrompt, int $candidates): ?JudgeAnswer
    {
        $this->recorder->begin(self::nominalSnapshot(), new PassContext(ServicingTriggerReason::CommentWritten, 0));

        try {
            $answer = $this->platform->object(
                $llm,
                self::SYSTEM_PROMPT,
                self::userPrompt($picture, $currentPrompt, $candidates),
                JudgeAnswer::class,
            );
        } catch (ModelUnavailable) {
            return null;
        } finally {
            // No NegotiationPass ever existed for this call -- finish(null)
            // still hands whatever tokens were recorded to the writer, which
            // is the point: see this class's own docblock.
            $this->recorder->finish(null);
        }

        $kept = array_values(array_filter(
            $answer->candidates,
            static fn(JudgeCandidate $candidate): bool => trim($candidate->prompt) !== '',
        ));

        if ($kept === []) {
            return null;
        }

        return new JudgeAnswer($answer->findings, $kept);
    }

    private static function userPrompt(DayPicture $picture, string $currentPrompt, int $candidates): string
    {
        return \sprintf(
            "Period statistics:\n%s\n\nCurrent strategy section:\n%s\n\nPropose up to %d candidate replacement texts.",
            $picture->describe(),
            $currentPrompt,
            $candidates,
        );
    }

    /** See this class's own docblock: there is no real quote behind a period-level judge call. */
    private static function nominalSnapshot(): QuoteSnapshot
    {
        return new QuoteSnapshot(
            new QuoteIdentity('', '', ''),
            new QuoteRevision('', new \DateTimeImmutable()),
            new QuoteTotals(0.0),
            new QuoteLifecycle(''),
            new QuoteContent(),
        );
    }
}
