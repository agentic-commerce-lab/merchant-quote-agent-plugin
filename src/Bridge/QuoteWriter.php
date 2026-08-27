<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Quote-level field writes, batched into one generic-DAL update.
 *
 * `customFields` is passed as the given top-level keys only, because the DAL
 * merges rather than replaces: CustomFieldsSerializer::encode() routes an
 * update of an existing entity through a JsonUpdateCommand, which
 * EntityWriteGateway executes as `JSON_SET(IFNULL(custom_fields, "{}"), …)` —
 * one path expression per given key. Untouched keys survive, which is what
 * keeps the A2CN act chain intact. UpdateQuoteTest verifies it against the live
 * shop rather than trusting this reading of the code.
 *
 * The one hole in that: `[]` does NOT reach the JsonUpdateCommand path, it
 * short-circuits to a literal `'{}'` and replaces the column. Hence the
 * non-empty check below.
 *
 * `discount` is written as SwagCommercial's own `{type, value}` JsonField and
 * only takes effect on the next recalculate(), where QuoteCalculator lifts it
 * onto the cart as QUOTE_DISCOUNT_EXTENSION for QuoteDiscountProcessor.
 */
final readonly class QuoteWriter
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quoteRepository */
    public function __construct(
        private EntityRepository $quoteRepository,
    ) {}

    public function write(string $quoteId, QuoteUpdate $update, Context $context): void
    {
        $fields = [];

        if ($update->discount !== null) {
            // Absolute discounts pass through unscaled. See Discount::$value:
            // the number is denominated in the quote's tax state, not in net.
            $fields['discount'] = [
                'type' => $update->discount->type->value,
                'value' => $update->discount->value,
            ];
        }

        if ($update->expiresAt !== null) {
            $fields['expirationDate'] = $update->expiresAt->format(\DATE_ATOM);
        }

        if ($update->customFields !== null && $update->customFields !== []) {
            $fields['customFields'] = $update->customFields;
        }

        // Covers both an empty QuoteUpdate (cf. QuoteUpdate::isEmpty()) and a
        // `customFields: []` that normalized away above. An id-only DAL update
        // is not free: it still bumps `updated_at`, which would move the
        // revision callers hold for optimistic locking and fail their next
        // write for nothing.
        if ($fields === []) {
            return;
        }

        $this->quoteRepository->update([['id' => $quoteId, ...$fields]], $context);
    }
}
