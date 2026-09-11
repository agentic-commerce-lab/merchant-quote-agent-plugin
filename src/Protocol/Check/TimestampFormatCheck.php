<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Override;

/**
 * A counterparty act that states a time in a format A2CN does not accept.
 *
 * The counterparty's act schema pins ISO-8601 UTC at second resolution with a
 * literal `Z`, and `\DATE_ATOM` — which is what a PHP or Python agent reaches
 * for by default — writes `+00:00` instead. Both name the same instant, so
 * nothing here is about correctness of the clock: it is about the BYTES. An
 * act whose timestamp is written the other way canonicalizes to a different
 * hash for any party that normalizes before hashing, and is refused outright
 * by a strict schema validator.
 *
 * Registered before TimestampMonotonicityCheck, because comparing two times
 * written in two formats is a comparison of nothing.
 *
 * The act is refused, never repaired. Rewriting `+00:00` to `Z` would change
 * the bytes the counterparty signed, and their own signature would then fail
 * against their own act.
 *
 * Only THEIR acts are checked. Ours go through ProtocolTimestamp and cannot
 * fail this; reporting one as a protocol violation would blame the
 * counterparty for our bug.
 */
final readonly class TimestampFormatCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        foreach ($chain->buyerActs($sellerDid) as $act) {
            if (!ProtocolTimestamp::matches($act->timestamp())) {
                return new ProtocolViolation(
                    timestamp: ProtocolTimestamp::of($at),
                    violationType: 'timestamp_format_invalid',
                    messageId: $act->messageId(),
                    description: \sprintf(
                        'act %s states timestamp as "%s", which is not ISO-8601 UTC at second resolution',
                        $act->messageId(),
                        $act->timestamp(),
                    ),
                );
            }

            $expires = $act->expiresAt();
            if ($expires !== null && !ProtocolTimestamp::matches($expires)) {
                return new ProtocolViolation(
                    timestamp: ProtocolTimestamp::of($at),
                    violationType: 'timestamp_format_invalid',
                    messageId: $act->messageId(),
                    description: \sprintf(
                        'act %s states expires_at as "%s", which is not ISO-8601 UTC at second resolution',
                        $act->messageId(),
                        $expires,
                    ),
                );
            }
        }

        return null;
    }
}
