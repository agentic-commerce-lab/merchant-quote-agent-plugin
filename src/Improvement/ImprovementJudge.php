<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;

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
    ) {}

    public function assess(ModelAccess $llm, DayPicture $picture, string $currentPrompt, int $candidates): ?JudgeAnswer
    {
        try {
            $answer = $this->platform->object(
                $llm,
                self::SYSTEM_PROMPT,
                self::userPrompt($picture, $currentPrompt, $candidates),
                JudgeAnswer::class,
            );
        } catch (ModelUnavailable) {
            return null;
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
}
