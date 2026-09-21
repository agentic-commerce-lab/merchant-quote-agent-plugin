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
 *
 * The free-text `comment` is itself an ask channel, not just a note:
 * `Negotiation\AskInterpreter` mines it for price asks downstream, and the
 * MODEL writes this comment when `targetSource` is `assistant_proposed`. So
 * provenance is stamped whenever the source is `assistant_proposed`, even
 * with an empty `$targets` — "the customer would like 15% off" typed by the
 * model into `comment` puts a model-authored figure in front of the policy
 * engine exactly like a `targets` entry would.
 *
 * A `ValidationException` from the gateway (empty cart, an unsupported
 * per-line ask, a customer without the quote feature) is caught and turned
 * into a structured `not_created` result instead of a broken chat turn —
 * the same posture {@see QuoteStatusTool} documents for `not_found`.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and every branch here is a
 * real case at the trust boundary a model-supplied argument crosses: an
 * invented `target_source` is a 422, a target missing a shape-valid product
 * id or unit price is a 422 rather than an undefined-array-key warning, a
 * `ValidationException` from the gateway becomes a structured refusal rather
 * than a broken chat turn, and the stamp fires on either an `assistant_proposed`
 * source or a non-empty `$targets`. None of that is incidental complexity.
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

    /** What the model is told when the gateway refused the request outright, so no quote exists at all. */
    private const NOTE_NOT_CREATED_SUFFIX =
        'No quote was created. Explain plainly to the shopper what is missing so they can fix it. Do not '
            . 'say a quote exists or was requested, and do not predict what the shop would have offered.';

    public function __construct(
        private readonly BuyerQuoteGatewayInterface $gateway,
        private readonly SalesChannelContext $context,
        private readonly AssistantAskStamp $askStamp,
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

        $lineItems = $this->lineItems($targets);

        try {
            $snapshot = $this->gateway->requestQuote(
                $this->context,
                $lineItems,
                mb_substr(trim($comment), 0, self::MAX_COMMENT),
            );
        } catch (ValidationException $error) {
            return [
                'quote_number' => '',
                'state' => 'not_created',
                'note' => $error->getMessage() . ' ' . self::NOTE_NOT_CREATED_SUFFIX,
            ];
        }

        if ([] !== $targets || 'assistant_proposed' === $targetSource) {
            $this->askStamp->stamp($snapshot, $targetSource);
        }

        return [
            'quote_number' => $snapshot->quoteNumber,
            'state' => $snapshot->state ?? 'open',
            'note' => self::NOTE,
        ];
    }

    /**
     * `$targets` is untrusted model-supplied JSON, so each entry is `mixed`,
     * not the `array{product_id: string, unit_price: float}` the public
     * docblock advertises to the model — that narrower shape is the tool's
     * schema contract, not a guarantee about what actually arrives here. The
     * shape check below is real validation of a caller that can send
     * anything; asserting the narrow shape in THIS docblock would make mago
     * read it as already proven and flag the check as redundant.
     *
     * @param list<mixed> $targets
     *
     * @return list<array{product_id: string, requested_unit_price: float}>
     */
    private function lineItems(array $targets): array
    {
        $lineItems = [];

        foreach ($targets as $index => $target) {
            if (
                !\is_array($target)
                || !\is_string($target['product_id'] ?? null)
                || '' === $target['product_id']
                || !is_numeric($target['unit_price'] ?? null)
            ) {
                throw new ValidationException('Each target needs a product id and a numeric unit price.', [
                    \sprintf('$.targets[%d] must have a string product_id and a numeric unit_price', $index),
                ]);
            }

            $lineItems[] = [
                'product_id' => $target['product_id'],
                'requested_unit_price' => (float) $target['unit_price'],
            ];
        }

        return $lineItems;
    }
}
