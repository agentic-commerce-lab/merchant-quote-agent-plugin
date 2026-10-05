<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `createdById` / `customerId` / `employeeId` are how a reader tells the three
 * writers of a quote comment apart: the buyer (customer/employee), the
 * merchant (createdById alone) and the agent (none of them — #3 measured all
 * three as null and AddCommentTest pins it).
 *
 * `isAuthored()` is the agent discriminator; `isBuyerAuthored()` is the ask
 * discriminator. Servicing\ServicingFingerprint and Negotiation\SnapshotAdapter
 * read the second one, and #55 is what happens when they read the first.
 */
final readonly class QuoteComment
{
    /**
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and every
     * construction site uses named arguments (see QuoteCommentMapper), so the
     * call-site complexity this rule exists to catch does not arise here.
     */
    public function __construct(
        public string $comment,
        public ?string $lineItemId = null,
        public ?string $createdById = null,
        public ?string $customerId = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?string $employeeId = null,
    ) {}

    public function isAuthored(): bool
    {
        return $this->createdById !== null || $this->customerId !== null || $this->employeeId !== null;
    }

    /**
     * The buyer's side of the conversation, which is NOT the same question as
     * `isAuthored()`.
     *
     * Three parties write on a quote and SwagCommercial gives each a different
     * column, measured against its own source (trunk 7.13.0, ad4947ee — every
     * quote_comment row in the tree is written by QuoteCommenter):
     *
     *  - the buyer, from the storefront: `customerId`, plus `employeeId` when
     *    a B2B employee is logged in. QuoteCommentRoute runs in store-api
     *    scope, whose SalesChannelApiSource can never yield an admin user, so
     *    `createdById` is null there — and the definition's CreatedByField
     *    cannot back-fill it, because its serializer requires an AdminApiSource.
     *  - the merchant, from the administration: `createdById` only.
     *    QuoteActionController passes customerId and employeeId as literal
     *    nulls.
     *  - the agent, from a message handler: nothing at all. A SystemSource
     *    yields no author of any kind (#3, pinned by AddCommentTest).
     *
     * So `isAuthored()` answers "did a person write this", which is what tells
     * the agent's own comments apart, and this answers "was it the buyer",
     * which is what tells an ASK apart from a merchant's internal note. #55:
     * one predicate doing both jobs meant a merchant's note queued a servicing
     * pass and got answered in the thread the customer reads.
     *
     * Positive on the buyer columns rather than negative on `createdById`, so
     * anything ambiguous counts as the buyer's and gets serviced: answering
     * something nobody asked is this issue's harm, and never answering a real
     * buyer is worse.
     */
    public function isBuyerAuthored(): bool
    {
        return $this->customerId !== null || $this->employeeId !== null;
    }

    /**
     * The administration's own comment: `createdById` and neither buyer
     * column — Negotiation\SnapshotAdapter's merchant bucket, and the read-model
     * twin of Servicing\QuoteServicingTrigger::isMerchantComment().
     */
    public function isMerchantAuthored(): bool
    {
        return $this->createdById !== null && !$this->isBuyerAuthored();
    }
}
