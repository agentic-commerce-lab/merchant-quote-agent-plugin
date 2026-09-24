<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Assistant\AssistantAskStamp;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Servicing\AgentDisclosure;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

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
    /** Only keys written by this plugin, plus the indexed act keys emitted by ActKey::for(). */
    private const OWN_CUSTOM_FIELDS = [
        AgentDisclosure::MARKER_KEY,
        AssistantAskStamp::ASK_SOURCE_KEY,
        MirroredAsks::KEY,
        QuoteBaseline::KEY,
        ClarificationMarker::MARKER_KEY,
        ActKey::SESSION_KEY,
        QuoteEscalator::MARKER_KEY,
        ServiceQuoteHandler::ATTEMPTS_KEY,
        ServicingFingerprint::MARKER_KEY,
    ];

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
        return \is_string($key) && (\in_array($key, self::OWN_CUSTOM_FIELDS, strict: true) || ActKey::isActKey($key));
    }
}
