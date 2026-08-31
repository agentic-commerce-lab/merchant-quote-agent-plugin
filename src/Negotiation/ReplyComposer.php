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
        private ChatCompletionClient $client,
        private PromptComposer $prompts,
        private LoggerInterface $logger,
        private DecisionRecorder $recorder,
    ) {}

    /**
     * @param float $reductionPercent how much the quote came down, measured on
     *                                the totals the database reports — the offer's own
     *                                `discountPercent` is null for a per-line concession
     *
     * @return string|null the reply prompt's hash, or null when the template wrote it
     */
    public function reply(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $after,
        QuoteAgentSettings $settings,
        float $reductionPercent,
        BuyerConversation $conversation,
    ): ?string {
        if (!$conversation->hasNewBuyerAsk() && $conversation->agent !== []) {
            $this->logger->info('This quote already carries an agent reply newer than the buyer ask; not answering twice.', [
                'quoteId' => $after->identity->quoteId,
            ]);

            return null;
        }

        $totalNet = $after->totals->totalNet;
        $validUntil = $after->lifecycle->expiresAt ?? new \DateTimeImmutable('+14 days');
        $template = ReplyTemplate::compose($reductionPercent, $totalNet, $after->identity->currencyIso, $validUntil);

        [$text, $hash] = $this->reword($settings, $template, $reductionPercent, $totalNet, $validUntil);

        $gateway->addComment($after->identity->quoteId, $text);
        $this->recorder->recordReply($text, $hash);
        $this->send($gateway, $after->identity->quoteId);

        return $hash;
    }

    /** @return array{0: string, 1: string|null} */
    private function reword(
        QuoteAgentSettings $settings,
        string $template,
        float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): array {
        $access = $settings->llm;

        if ($settings->rulesOnly || $access === null) {
            return [$template, null];
        }

        $prompt = $this->prompts->reply($settings);

        try {
            $reworded = trim($this->client->complete($access, $prompt->text, $template, json: false));
        } catch (ModelUnavailable $e) {
            // The offer is already applied. A plainer sentence beats no
            // sentence, so the template ships and the pass still succeeds.
            $this->logger->warning('The reply could not be reworded; sending the template instead.', [
                'exception' => $e,
            ]);

            return [$template, null];
        }

        if (!ReplyTemplate::keepsTheFacts($reworded, $reductionPercent, $totalNet, $validUntil)) {
            $this->logger->warning('The reworded reply dropped a fact; sending the template instead.', [
                'reworded' => $reworded,
            ]);

            return [$template, null];
        }

        return [$reworded, $prompt->hash];
    }

    /**
     * Public because the recovery path calls it: see
     * OfferRound::finishStrandedReply(). Swallowing the illegal transition is
     * the point — the comment is already with the buyer, so a state we cannot
     * move is worth a log line and nothing more.
     */
    public function send(QuoteGatewayInterface $gateway, string $quoteId): void
    {
        try {
            $gateway->transition($quoteId, QuoteTransition::Sent);
        } catch (IllegalTransitionException $e) {
            $this->logger->info('The quote could not be moved to replied; the comment stands.', [
                'quoteId' => $quoteId,
                'exception' => $e,
            ]);
        }
    }
}
