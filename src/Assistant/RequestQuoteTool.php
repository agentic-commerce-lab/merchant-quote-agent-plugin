<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Ucp\Sdk\Exception\ValidationException;

/**
 * Turns the shopper's cart into an ordinary hand-made storefront quote. The
 * merchant's negotiation agent replies to it minutes later, asynchronously —
 * this tool never sees a price back.
 */
#[AsTool(
    name: 'request_quote',
    description: 'Ask the shop for a quote on everything in the shopper\'s cart. Use when the '
    . 'shopper asks for a discount, a bulk price, or a quote. The shop replies later, not now.',
)]
final class RequestQuoteTool
{
    private const MAX_COMMENT = 2_000;

    private const SOURCES = ['buyer_stated', 'assistant_proposed'];

    /**
     * What the model is told once the quote exists.
     *
     * The prohibition is explicit for the reason the starter kit's EscalateTool
     * documents from six-of-six live runs: given a result and no instruction, a
     * model narrates an outcome. There is no outcome yet — the merchant agent
     * has not looked at this quote — so every such sentence is a false claim
     * about the merchant's operations, made to a customer.
     */
    private const NOTE =
        'Say that the request is with the shop and that they will reply, and give the quote number. '
            . 'Nothing has been decided: do not say a discount was granted, approved, applied or '
            . 'secured, do not predict what the shop will offer, and do not state any price or '
            . 'percentage for this quote.';

    public function __construct(
        private readonly BuyerQuoteGatewayInterface $gateway,
        private readonly SalesChannelContext $context,
    ) {}

    /**
     * @param string $comment The shopper's own words about what they want, in one or two sentences.
     * @param list<array{product_id: string, unit_price: float}> $targets Per-unit prices to ask for.
     * @param string $targetSource Either `buyer_stated` or `assistant_proposed`.
     *
     * @return array{quote_number: string, state: string, note: string}
     */
    public function __invoke(string $comment, array $targets = [], string $targetSource = 'buyer_stated'): array
    {
        if (!\in_array($targetSource, self::SOURCES, true)) {
            throw new ValidationException('Unknown target source.', [
                '$.target_source must be buyer_stated or assistant_proposed',
            ]);
        }

        $lineItems = array_map(static fn(array $target): array => [
            'product_id' => $target['product_id'],
            'requested_unit_price' => $target['unit_price'],
        ], $targets);

        $snapshot = $this->gateway->requestQuote(
            $this->context,
            $lineItems,
            mb_substr(trim($comment), 0, self::MAX_COMMENT),
        );

        return [
            'quote_number' => $snapshot->quoteNumber,
            'state' => $snapshot->state ?? 'open',
            'note' => self::NOTE,
        ];
    }
}
