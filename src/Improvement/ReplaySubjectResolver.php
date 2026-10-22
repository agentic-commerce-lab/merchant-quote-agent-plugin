<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use MerchantQuoteAgentPlugin\Audit\InterpretationPayload;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Bridge\QuoteSnapshotReader;
use MerchantQuoteAgentPlugin\Negotiation\InterpretedAsk;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use Shopware\Core\Framework\Context;

/**
 * One harvested decision, turned into a ReplaySubject or a reason to skip it.
 *
 * `interpretedAsks` is read RAW, exactly what DecisionRecorder::recordAsk()
 * stored via InterpretationPayload::of() -- never through
 * AnonymizedDecision::asks()'s stripped shape. That export strip drops
 * `clarificationQuestions` and `humanReviewRequests`, and
 * InterpretationHydrator::hydrate() refuses any payload missing either key
 * (see its TOP_LEVEL_KEYS guard) -- so stripping first would make every
 * rehydration fail and every decision count as skipped, silently, forever.
 * ReplaySubjectResolverTest::testARealisticStoredPayloadRehydrates() pins the
 * raw shape as the one that actually rehydrates. The strip exists for
 * DayPicture's model-facing aggregate, which never reads `interpretedAsks` at
 * all (see that class's own docblock) -- this class runs entirely in-process
 * and never sends the ask to a model, so the privacy budget that motivated the
 * strip does not apply here.
 *
 * `interpretedAsks === null` is a DIFFERENT case from a payload that fails to
 * rehydrate: DecisionRecorder::recordAsk() is only ever called when a comment
 * was actually interpreted, so null means no extraction ran for this pass --
 * a real, common outcome (a structured-only ask, or nothing to do) -- and
 * ReplayEvaluator::replay() takes `?InterpretedAsk` precisely so that case
 * replays honestly instead of being skipped.
 *
 * A missing QuoteBaseline is always a skip: every harvested decision is, by
 * definition, a quote that was already serviced (a decision row exists),
 * and ServiceQuoteHandler::claimAttempt() stamps the baseline before any
 * pipeline stage runs. An absent baseline here means the anchor production
 * relied on is gone, and ReplayEvaluator would silently fall back to the
 * LIVE (possibly already-discounted) snapshot as its own anchor -- corrupting
 * the comparison rather than failing loudly. Skipping here is what keeps that
 * from happening quietly.
 *
 * Reading QuoteVersion::Live here means the CONVERSATION every arm replays
 * against can carry comments written after the decision being replayed, even
 * though the price anchor is pinned to that decision's own moment (see
 * QuoteBaseline above) -- ReplayEvaluator's own docblock spells this out.
 * Accepted rather than fixed: every arm sees the identical drifted text, so
 * the A/B delta stays attributable to the strategy prompt, and
 * ControlDivergence exists precisely to catch a night where that drift (or
 * anything else) has pulled the control arm's own numbers away from what
 * actually happened.
 */
final readonly class ReplaySubjectResolver
{
    public function __construct(
        private QuoteSnapshotReader $quotes,
    ) {}

    public function resolve(HarvestedDecision $decision, Context $context): ?ReplaySubject
    {
        try {
            $snapshot = $this->quotes->read($decision->quoteId, QuoteVersion::Live, $context);
        } catch (QuoteNotFoundException) {
            return null;
        }

        $ask = self::ask($decision);

        if ($ask === false) {
            return null;
        }

        if (QuoteBaseline::read($snapshot) === null) {
            return null;
        }

        return new ReplaySubject($snapshot, $ask, $decision);
    }

    /** false signals "present but unusable"; null signals "legitimately absent". */
    private static function ask(HarvestedDecision $decision): InterpretedAsk|false|null
    {
        if ($decision->interpretedAsks === null) {
            return null;
        }

        $interpretation = InterpretationPayload::from($decision->interpretedAsks);

        if ($interpretation === null) {
            return false;
        }

        return new InterpretedAsk($interpretation, $decision->extractPromptHash ?? '');
    }
}
