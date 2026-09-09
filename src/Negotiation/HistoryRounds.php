<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/** Runs bounded account reads before a proposal is ready for authorization. */
final readonly class HistoryRounds
{
    private const HISTORY_ROUNDS = 2;

    public function __construct(
        private ModelPlatform $platform,
        private DecisionRecorder $recorder,
        private CustomerHistoryInterface $history,
    ) {}

    /**
     * @param list<QuoteLineSnapshot> $lines
     * @throws ModelUnavailable
     * @throws HistoryBudgetExhausted
     */
    public function negotiate(
        ModelAccess $access,
        ComposedPrompt $prompt,
        string $user,
        array $lines,
    ): NegotiateResponse {
        $summary = $this->history->summary();
        $this->recorder->recordHistorySummary($summary);
        $brief = CustomerBrief::of($summary);
        $user .= $brief === '' ? '' : "\n\n" . $brief;
        $rounds = 0;

        while (true) {
            $response = $this->platform->object($access, $prompt->text, $user, NegotiateResponse::class);

            if (!$response->wantsHistory()) {
                return $response;
            }

            if ($rounds === self::HISTORY_ROUNDS) {
                throw HistoryBudgetExhausted::after($rounds, $response);
            }

            $result = (new HistoryRequestResolver())->resolve($response->historyRequest, $this->history, $lines);
            $this->recorder->recordHistoryRound($response->historyRequest, $result);
            $user .= "\n\n" . $result;
            ++$rounds;
        }
    }
}
