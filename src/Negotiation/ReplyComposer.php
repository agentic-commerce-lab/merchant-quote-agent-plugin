<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
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

        // The GROSS total, never the net one. Live quote 1020 told a buyer who
        // owed 8226.60 that their new total was 6913.11, because this reached
        // for totalNet -- 19% understated, and invisible on the 0%-tax quote
        // next to it where the two figures are equal.
        $total = $after->totals->buyerFacingTotal();
        $validUntil = $after->lifecycle->expiresAt ?? new \DateTimeImmutable('+14 days');
        $template = $reductionPercent === null
            ? ReplyTemplate::holds($total, $after->identity->currencyIso, $validUntil)
            : ReplyTemplate::compose($reductionPercent, $total, $after->identity->currencyIso, $validUntil);

        [$text, $hash] = $this->reword($settings, $template, $reductionPercent, $total, $validUntil);

        $gateway->addComment($after->identity->quoteId, $text);
        $this->recorder->recordReply($text, $hash);
        $this->send($gateway, $after->identity->quoteId, $after->lifecycle->stateTechnicalName);

        return $hash;
    }

    /** @return array{0: string, 1: string|null} */
    private function reword(
        QuoteAgentSettings $settings,
        string $template,
        ?float $reductionPercent,
        float $total,
        \DateTimeImmutable $validUntil,
    ): array {
        $access = $settings->llm;

        $prompt = $this->prompts->reply($settings);

        try {
            $reworded = trim($this->platform->text($access, $prompt->text, $template));
        } catch (ModelUnavailable $e) {
            // The offer is already applied. A plainer sentence beats no
            // sentence, so the template ships and the pass still succeeds.
            return $this->fallback($template, 'The reply could not be reworded; sending the template instead.', [
                'exception' => $e,
            ]);
        }

        $unsafe = RewordingGuard::unsafeBecause($reworded, $reductionPercent, $total, $validUntil);

        if ($unsafe !== null) {
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
            $this->logger->error('The quote could not be moved to replied; the buyer has a discount they cannot accept.', [
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
    private static function transitionFor(string $state): QuoteTransition
    {
        return match ($state) {
            'reopen', 'change_requested' => QuoteTransition::AdminResend,
            default => QuoteTransition::Sent,
        };
    }
}
