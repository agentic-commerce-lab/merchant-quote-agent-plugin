<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Model call 3, and the last write of a pass.
 *
 * The comment is posted BEFORE the `sent` transition and both happen before
 * the handler's fingerprint stamp, so a crash anywhere yields a clean retry.
 * The retry is safe because of the guard below: an agent comment already newer
 * than the buyer's newest ask means this pass has been answered, and a second
 * message to a buyer is the one thing a retry must never produce.
 */
final readonly class ReplyComposer
{
    /**
     * Where the sentence the model may reword starts.
     *
     * Public because the reply call's user message is two parts now, and the
     * test fixture that derives a valid rewording from it
     * (`PipelineFixture::reworded()`) has to find the template half — a
     * marker spelled out there would drift the first time this wording moved.
     */
    public const TEMPLATE_HEADING = 'Reply to reword:';

    public function __construct(
        private ModelPlatform $platform,
        private PromptComposer $prompts,
        private LoggerInterface $logger,
        private DecisionRecorder $recorder,
    ) {}

    /**
     * @param ?float $reductionPercent how much the quote came down, measured on
     *                                 the totals the database reports — the offer's own
     *                                 `discountPercent` is null for a per-line concession.
     *                                 Null when this pass granted nothing (#175): the reply
     *                                 then states the hold, not a 0% reduction.
     *
     * @return string|null the reply prompt's hash, or null when the template wrote it
     */
    public function reply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $after,
        QuoteAgentSettings $settings,
        ?float $reductionPercent,
        BuyerConversation $conversation,
    ): ?string {
        if (!$conversation->hasNewBuyerAsk() && $conversation->agent !== []) {
            $this->logger->info('This quote already carries an agent reply newer than the buyer ask; not answering twice.', [
                'quoteId' => $after->identity->quoteId,
            ]);

            return null;
        }

        [$text, $hash] = $this->reword($settings, $after, $conversation->newestBuyerText(), $reductionPercent);

        $gateway->addComment($after->identity->quoteId, $text);
        $this->recorder->recordReply($text, $hash);
        $this->send($gateway, $after->identity->quoteId, $after->lifecycle->stateTechnicalName);

        return $hash;
    }

    /**
     * The reply to a comment that held no ask (see PassedOver): the quote as
     * it stands, posted as written. No model call, so there is no rewording
     * for RewordingGuard to check and no reply prompt hash to record.
     *
     * No already-answered guard, unlike reply(): PassedOver only gets here
     * with an interpreted ask, and AskInterpreter only interprets a buyer
     * comment newer than every agent one. A retry after this comment landed
     * reads the agent as newest and never arrives.
     *
     * Same order as reply(): comment, record, then the transition that makes
     * the standing offer acceptable again.
     */
    public function acknowledge(QuoteGatewayInterface $gateway, QuoteSnapshot $snapshot): void
    {
        $text = ReplyTemplate::acknowledges(
            $snapshot->totals->buyerFacingTotal(),
            $snapshot->identity->currencyIso,
            $snapshot->lifecycle->expiresAt,
        );

        $gateway->addComment($snapshot->identity->quoteId, $text);
        $this->recorder->recordReply($text, null);
        $this->send($gateway, $snapshot->identity->quoteId, $snapshot->lifecycle->stateTechnicalName);
    }

    /**
     * The buyer's own ask above the template, or the template alone when
     * there is none.
     *
     * Live quote 1054 is why the ask is here at all: the buyer wrote "Can we
     * get a discount, my max budget is 9k" and read back "We have reduced the
     * quote by 15% to 9885.58 EUR." — correct, and a form letter, because the
     * template was the whole of what the reply model had ever seen. It could
     * not acknowledge a target nobody showed it, and no strategy prompt could
     * fix that: the strategy reaches this call as a tone sentence only
     * (`PromptComposer::toneFrom()`).
     *
     * The ask is context for the WORDING, never a second source of facts, and
     * it does NOT relax `RewordingGuard`: every figure the buyer wrote is a
     * figure nobody authorised, so a rewording that quotes their target back
     * at them falls back to the template exactly like an invented one does.
     * That is the whole safety argument for putting untrusted buyer text in
     * the last prompt before a buyer — the guard never learned to trust it,
     * and the reply model holds no history to leak even if it were talked
     * into trying (`HistoryInjectionAssertions::assertPrivateReplyBoundary()`
     * pins the prompt to exactly this string).
     *
     * It goes in the USER message, not the system prompt: `reply_prompt_hash`
     * identifies a prompt VERSION across quotes, and a per-quote system
     * prompt would give every pass its own hash and make that column useless.
     */
    public static function userMessage(string $ask, string $template): string
    {
        $ask = trim($ask);

        if ($ask === '') {
            return $template;
        }

        return "The buyer wrote:\n" . $ask . "\n\n" . self::TEMPLATE_HEADING . "\n" . $template;
    }

    /**
     * Composing the template and rewording it live together because the
     * template is both halves of the outcome: the text the model is asked to
     * reword, and the text that ships when the rewording is rejected.
     *
     * @return array{0: string, 1: string|null}
     */
    /**
     * Public for Review\DraftReply, which re-drafts a reply against a
     * merchant's edited prices through exactly this path, guard and fallback
     * included.
     *
     * @return array{0: string, 1: string|null}
     */
    public function reword(
        QuoteAgentSettings $settings,
        QuoteSnapshot $after,
        string $ask,
        ?float $reductionPercent,
    ): array {
        $access = $settings->llm;

        // The GROSS total, never the net one. Live quote 1020 told a buyer who
        // owed 8226.60 that their new total was 6913.11, because this reached
        // for totalNet -- 19% understated, and invisible on the 0%-tax quote
        // next to it where the two figures are equal.
        $total = $after->totals->buyerFacingTotal();
        $validUntil = $after->lifecycle->expiresAt ?? new \DateTimeImmutable('+14 days');
        $template = $reductionPercent === null
            ? ReplyTemplate::holds($total, $after->identity->currencyIso, $validUntil)
            : ReplyTemplate::compose($reductionPercent, $total, $after->identity->currencyIso, $validUntil);

        $prompt = $this->prompts->reply($settings);

        try {
            $reworded = trim($this->platform->text($access, $prompt->text, self::userMessage($ask, $template)));
        } catch (ModelUnavailable $e) {
            // The offer is already applied. A plainer sentence beats no
            // sentence, so the template ships and the pass still succeeds.
            return $this->fallback($template, 'The reply could not be reworded; sending the template instead.', [
                'exception' => $e,
            ]);
        }

        $unsafe = RewordingGuard::unsafeBecause($reworded, $reductionPercent, $total, $validUntil);

        if ($unsafe !== null) {
            // The reason goes to content, not meta: RewordingGuard quotes the
            // model's own words in it ("it names a concession nobody
            // authorised: 10%"), and meta leaves in every export.
            $this->recorder->trace(
                TraceKind::ReplyGuard,
                ['accepted' => false],
                [
                    'reason' => $unsafe,
                    'reworded' => $reworded,
                ],
            );

            // Logged with the reason, not just the text: the fallback is a
            // correct reply, so an over-firing guard fails nothing and shows
            // up nowhere except as replies that never sound reworded. The
            // reason makes "always the same rule" one grep.
            return $this->fallback(
                $template,
                'The reworded reply did not survive the guard; sending the template instead.',
                [
                    'reason' => $unsafe,
                    'reworded' => $reworded,
                ],
            );
        }

        return [$reworded, $prompt->hash];
    }

    /**
     * Both callers reach here with a correct reply already composed — the
     * model produced something unusable, or the guard rejected it — and
     * nothing left to do but hand the template back. That is exactly when a
     * logger that throws costs the most: it turns a handled case into an
     * unanswered buyer, one line before the fallback would have shipped.
     * Shopware's monolog stack can throw in ordinary operation — a full disk,
     * a failing handler, a misconfigured remote sink — this is not a
     * hypothetical about a deliberately hostile logger. It follows the
     * neighbours: `NegotiationPipeline::record()` wraps its own logging in
     * `catch (\Throwable)` for the same reason, `ShopwareEscalationNotifier::attempt()`
     * catches per channel so one failing delivery still lets the others
     * through, and `QuoteEscalator` guards the notifier even though its
     * contract forbids throwing.
     *
     * @param array<string, mixed> $context
     *
     * @return array{0: string, 1: null}
     */
    private function fallback(string $template, string $message, array $context): array
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // @mago-expect lint:no-empty-catch-clause
            // Deliberately empty: there is nowhere left to report a failure
            // of the reporting channel itself, and the template must still
            // ship to the buyer.
        }

        return [$template, null];
    }

    /**
     * Public because the recovery path calls it: see
     * OfferRound::finishStrandedReply(). Picks the transition that reaches
     * `replied` for the state the quote is ACTUALLY in when the reply lands —
     * which is not necessarily the state the pass started in, since a
     * successful claim earlier in the pass (OfferApplier) can move `open` to
     * `in_review`. `sent` works from `open`/`in_review`; the renegotiation
     * states have no `sent` at all and need `admin_resend` instead — `reopen`
     * on released SwagCommercial (≤6.7.12), `change_requested` on trunk.
     *
     * An illegal transition here is NOT swallowed as harmless. It used to be,
     * on the reasoning that "the comment is already with the buyer, so a
     * state we cannot move is worth a log line and nothing more" — but
     * reaching `replied` is what makes the offer ACCEPTABLE, and that
     * reasoning is exactly how live quote #1021 was stranded: 10% granted,
     * reply sent, state stuck at `reopen`, no accept path, nothing said out
     * loud. A buyer told they got a discount who then cannot order is worse
     * than an escalation. So a failed transition now logs at error level and
     * marks the audit record as an incomplete pass — the buyer-facing
     * comment still stands either way, but the pass does not get to report
     * success quietly.
     */
    public function send(QuoteGatewayInterface $gateway, string $quoteId, string $state): void
    {
        $action = self::transitionFor($state);

        try {
            $gateway->transition($quoteId, $action);
        } catch (IllegalTransitionException $e) {
            $this->logger->error('The quote could not be moved to replied; the buyer cannot accept the offer standing on it.', [
                'quoteId' => $quoteId,
                'state' => $state,
                'action' => $action->value,
                'exception' => $e,
            ]);

            $this->recorder->recordReplyTransitionFailed(sprintf(
                'Reply posted but %s from %s did not reach replied.',
                $action->value,
                $state,
            ));
        }
    }

    /** The renegotiation states share `admin_resend` as their only exit to `replied`; everything else uses `sent`. */
    public static function transitionFor(string $state): QuoteTransition
    {
        return match ($state) {
            'reopen', 'change_requested' => QuoteTransition::AdminResend,
            default => QuoteTransition::Sent,
        };
    }
}
