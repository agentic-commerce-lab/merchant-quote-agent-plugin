<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Audit\TraceWrite;
use MerchantQuoteAgentPlugin\Audit\TraceWriterInterface;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What happened to a quote the shopper asked for.
 *
 * Reads only the buyer's own quotes — the gateway scopes every listing to the
 * authenticated customer — but only the most recent PAGE_SIZE (25) of them.
 * A miss is `not_found`, but that is NOT proof the number belongs to someone
 * else: it may simply be older than the shopper's 25 most recent quotes, and
 * NOTE_NOT_FOUND says exactly that rather than the false "belongs to someone
 * else" a naive reading of the miss would suggest.
 */
#[AsTool(
    name: 'quote_status',
    description: 'Check what the shop replied to a quote the shopper requested. '
    . 'Use when they ask about a quote they already asked for.',
)]
final class QuoteStatusTool
{
    private const PAGE_SIZE = 25;

    /** Figures are formatted here and repeated verbatim; a re-rounded price shown to a shopper is a wrong price. */
    private const NOTE =
        'State the quote\'s status and, if it has one, its total and validity date exactly as given '
            . 'here. Do not recalculate, round or convert any figure, and do not state a discount '
            . 'percentage.';

    private const NOTE_NOT_FOUND =
        'That quote number is not among the shopper\'s most recent 25 quotes. Say so plainly, mention that '
            . 'older quotes are visible in their account, and offer to look again or to request a new quote. '
            . 'Do not guess a number.';

    public function __construct(
        private readonly BuyerQuoteGatewayInterface $gateway,
        private readonly SalesChannelContext $context,
        private readonly ?TraceWriterInterface $traces = null,
    ) {}

    /**
     * @param string|null $quoteNumber The quote number to look up; omit for the shopper's most recent quote.
     *
     * @return array{state: string, total: string, valid_until: string, note: string}
     */
    public function __invoke(?string $quoteNumber = null): array
    {
        $quotes = $this->gateway->listQuotes($this->context, self::PAGE_SIZE, 1)->quotes;

        $quote = null;
        foreach ($quotes as $candidate) {
            if (null === $quoteNumber || $candidate->quoteNumber === $quoteNumber) {
                $quote = $candidate;
                break;
            }
        }

        if (!$quote instanceof QuoteSnapshot) {
            return $this->record($quoteNumber, [
                'state' => 'not_found',
                'total' => '',
                'valid_until' => '',
                'note' => self::NOTE_NOT_FOUND,
            ]);
        }

        return $this->record(
            $quoteNumber,
            [
                'state' => $quote->state ?? 'unknown',
                'total' => $this->money($quote),
                'valid_until' => $quote->expirationDate ?? '',
                'note' => self::NOTE,
            ],
            $quote->id,
        );
    }

    /**
     * @param array{state: string, total: string, valid_until: string, note: string} $output
     *
     * @return array{state: string, total: string, valid_until: string, note: string}
     */
    private function record(?string $quoteNumber, array $output, ?string $quoteId = null): array
    {
        try {
            $this->traces?->write(
                new TraceWrite(
                    TraceKind::AssistantTool,
                    ['tool' => 'quote_status', 'status' => $output['state']],
                    ['input' => ['quoteNumber' => $quoteNumber], 'output' => $output],
                    $quoteId,
                    $this->context->getCustomer()?->getId(),
                ),
            );
        } catch (\Throwable) {
            // Evidence cannot change a tool response after the gateway has run.
        }

        return $output;
    }

    /**
     * `taxStatus` says which of the two totals is the customer-facing
     * authoritative amount — that is part of the published snapshot contract,
     * and picking the wrong one here would show a net figure to a gross-priced
     * shopper.
     */
    private function money(QuoteSnapshot $quote): string
    {
        $amount = 'gross' === $quote->taxStatus ? $quote->totalGross : $quote->totalNet;

        if (null === $amount) {
            return '';
        }

        return \sprintf('%s %s', number_format($amount, 2, '.', ''), $quote->currency ?? '');
    }
}
