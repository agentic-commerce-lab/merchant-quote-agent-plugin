<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * A quote snapshot as a trace event, for `quote_before` and `quote_after`
 * alike, so the two cannot drift apart.
 *
 * The whole snapshot is content, except two identity fields that are dropped
 * at recording. `companyName` never reaches the model -- it only feeds the
 * A2CN parties block -- and `orderId` is a raw id outside the export's
 * pseudonym promise. Neither is needed to measure a strategy, and keeping
 * them would put the customer's name next to its scrambled code in every
 * export with comments, which docs/for-merchants.md promises never happens.
 * Dropped here rather than at export time so the stored row never holds them
 * either.
 *
 * Never throws, like everything on the recording path: when the encoded
 * snapshot has no identity to strip (TracePayload substitutes `[]` for a
 * value it could not encode), the content is recorded as it is.
 */
final class QuoteTrace
{
    private function __construct() {}

    /** @return array{0: array<string, mixed>, 1: array<array-key, mixed>} */
    public static function of(QuoteSnapshot $snapshot): array
    {
        $content = TracePayload::of($snapshot);
        $identity = $content['identity'] ?? null;

        if (\is_array($identity)) {
            unset($identity['companyName'], $identity['orderId']);
            $content['identity'] = $identity;
        }

        return [['lineCount' => \count($snapshot->content->lines)], $content];
    }
}
