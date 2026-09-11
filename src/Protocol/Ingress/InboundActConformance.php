<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Check\ActVerifier;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;

/**
 * Is this act evidence we can stand behind once it is on the chain.
 *
 * Every rule here has a matching EvidenceCheck that would catch the same
 * problem later. The point of catching it HERE is that later is too late: a
 * chain refused by EvidenceInspector never gets another seller act, for the
 * life of that quote, and the buyer has no way to withdraw what they wrote.
 * A 4xx at the door is the same judgement delivered while it can still be
 * acted on.
 *
 * Strictly append-only at `nextSequence()`. Not "any unused sequence": a gap
 * would leave the chain claiming a round that never happened, and a lower
 * number would rewrite history that another party may already have hashed.
 */
final readonly class InboundActConformance
{
    public function __construct(
        private ActVerifier $verifier,
    ) {}

    public function refusal(Act $act, ActChain $chain): ?InboundActRefusal
    {
        $expires = $act->expiresAt();
        if (
            !ProtocolTimestamp::matches($act->timestamp())
            || $expires !== null && !ProtocolTimestamp::matches($expires)
        ) {
            return new InboundActRefusal(400, 'timestamp_format_invalid');
        }

        if (self::isInverted($act, $chain)) {
            return new InboundActRefusal(409, 'timestamp_inversion');
        }

        if ($act->sequenceNumber() !== $chain->nextSequence()) {
            return new InboundActRefusal(409, 'sequence_conflict');
        }

        return $this->verifier->reasonItDoesNotVerify($act) === null
            ? null
            : new InboundActRefusal(403, 'act_unverified');
    }

    private static function isInverted(Act $act, ActChain $chain): bool
    {
        $previous = $chain->last();
        if ($previous === null) {
            return false;
        }

        $before = strtotime($previous->timestamp());
        $now = strtotime($act->timestamp());

        // An unreadable predecessor is not this act's fault; the format rule
        // above already guarantees THIS act is readable.
        return $before !== false && $now !== false && $now < $before;
    }
}
