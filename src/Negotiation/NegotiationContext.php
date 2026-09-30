<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/** Quote-owned identity and conversation carried unchanged through a negotiation round. */
final readonly class NegotiationContext
{
    /**
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and the fields
     * past the first four are named at their construction site (OfferRound),
     * so the call-site complexity this rule exists to catch does not arise.
     */
    public function __construct(
        public string $customerId,
        public string $quoteId,
        public BuyerConversation $conversation,
        public ?QuoteBaselineLines $baseline = null,
        /**
         * The whole-quote total the buyer named, already out of their own tax
         * space — see BuyerPriceSpace. Null when they named no total.
         *
         * Carried here rather than derived downstream because the negotiate
         * prompt is the only stage that both prices in net and reads the
         * buyer's own sentence, where the figure is still gross.
         */
        public ?float $buyerTargetNet = null,
        /**
         * The per-line targets the buyer's comment asked for, as the policy
         * layer adopts them (CommentTargetMerger::adopted()), net. #222: the
         * prompt's "buyer asks per unit net" column read only the storefront
         * field, so a price typed in the comment reached the model solely as
         * the buyer's gross sentence.
         *
         * @var array<string, float> line item id => target unit price, net
         */
        public array $lineAsksNet = [],
        /** True when the quote is stored gross, so the figures the buyer writes include tax. */
        public bool $buyerWritesGross = false,
    ) {}
}
