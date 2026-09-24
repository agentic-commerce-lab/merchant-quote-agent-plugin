<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * A quote snapshot as a trace event, for `quote_before` and `quote_after`
 * alike, so the two cannot drift apart.
 *
 * The whole snapshot is content, except what is dropped at recording:
 *
 *  - `identity.companyName` never reaches the model -- it only feeds the A2CN
 *    parties block -- and `identity.orderId` is a raw id outside the export's
 *    pseudonym promise;
 *  - every `lifecycle.customFields` key the plugin does not own. The merchant
 *    defines those, and one can hold a contact's name or e-mail. The plugin's
 *    own keys -- the agent's state markers and the A2CN session and acts --
 *    are the part worth analysing, and they stay.
 *
 * None of the dropped values is needed to measure a strategy, and keeping
 * them would put a name next to the customer's scrambled code in every export
 * with comments, which docs/for-merchants.md promises never happens. Dropped
 * here rather than at export time so the stored row never holds them either.
 *
 * Never throws, like everything on the recording path: when the encoded
 * snapshot has no identity or custom fields to strip (TracePayload
 * substitutes `[]` for a value it could not encode), the content is recorded
 * as it is.
 */
final class QuoteTrace
{
    /** The custom-field keys this plugin writes (Audit markers, Assistant, Protocol\Act\ActKey); anything else is the merchant's. */
    private const OWN_CUSTOM_FIELD_PREFIXES = ['merchant_quote_agent_', 'merchantQuoteAgent', 'a2cn_'];

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

        $lifecycle = $content['lifecycle'] ?? null;

        if (\is_array($lifecycle) && \is_array($lifecycle['customFields'] ?? null)) {
            $lifecycle['customFields'] = array_filter(
                $lifecycle['customFields'],
                self::isOwn(...),
                ARRAY_FILTER_USE_KEY,
            );
            $content['lifecycle'] = $lifecycle;
        }

        return [['lineCount' => \count($snapshot->content->lines)], $content];
    }

    private static function isOwn(int|string $key): bool
    {
        foreach (self::OWN_CUSTOM_FIELD_PREFIXES as $prefix) {
            if (str_starts_with((string) $key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
