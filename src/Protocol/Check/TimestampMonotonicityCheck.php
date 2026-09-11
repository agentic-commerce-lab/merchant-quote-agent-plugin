<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\ProtocolTimestamp;
use Override;

/**
 * An act that claims to have happened before the act it follows.
 *
 * The chain's order is its sequence, and a reader takes that order to be
 * causal: act n answers act n-1. An offer timestamped after the counteroffer
 * answering it is not a timeline any auditor accepts, and signing further
 * into it would attest a history we cannot defend. Observed live on
 * 2026-09-10: a seller counteroffer 17 seconds older than the buyer offer it
 * answered, because the buyer had rewritten act 1 in place after we signed.
 *
 * Checked over the WHOLE chain, ours included — the observed inversion was
 * between our act and theirs, and a check that only compared their acts to
 * each other would have reported it clean, exactly as the four original
 * checks did.
 *
 * Compared as instants, not as strings. Zulu strings happen to sort
 * chronologically, but this check must also be right about an act written
 * before TimestampFormatCheck existed, and about one this plugin refuses but
 * still has to read.
 *
 * Zero tolerance for clock skew between the two parties. The inbound act
 * route refuses an inverted act while the buyer can still correct it, so a
 * chain only arrives here inverted if it was written some other way — and a
 * window wide enough to absorb honest skew is wide enough to absorb the
 * inversion this exists to catch.
 * ponytail: no skew window. Add one only against a real counterparty whose
 * clock is provably off and who cannot fix it.
 */
final readonly class TimestampMonotonicityCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $previous = null;
        foreach ($chain->acts() as $act) {
            $instant = strtotime($act->timestamp());
            if ($instant === false) {
                // Unreadable, so not comparable. TimestampFormatCheck owns
                // the complaint about how it is written; this check has
                // nothing to say and must not swallow the act silently by
                // treating it as zero.
                continue;
            }

            if ($previous !== null && $instant < $previous) {
                return new ProtocolViolation(
                    timestamp: ProtocolTimestamp::of($at),
                    violationType: 'timestamp_inversion',
                    messageId: $act->messageId(),
                    description: \sprintf(
                        'act %s is timestamped %s, before the act it follows',
                        $act->messageId(),
                        $act->timestamp(),
                    ),
                );
            }

            $previous = $instant;
        }

        return null;
    }
}
