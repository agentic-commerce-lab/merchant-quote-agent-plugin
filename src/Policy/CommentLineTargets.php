<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * The per-line target-price asks a comment carries: which ones it names, and
 * which of those the quote lets stand.
 *
 * Both questions live here rather than on CommentTargetMerger because that
 * class is at the gate's class-complexity ceiling, and because both are about
 * the TARGETS — the merger's own job is applying them to a snapshot and
 * rescaling the buyer's total off the result.
 */
final class CommentLineTargets
{
    /** @return array<string, float> */
    public function extract(?CommentInterpretation $interpretation): array
    {
        $targets = [];
        foreach ($interpretation?->structural->lineChanges ?? [] as $change) {
            if ($change->targetUnitPrice === null) {
                continue;
            }
            $targets[$change->lineItemId] = $change->targetUnitPrice;
        }

        return $targets;
    }

    /**
     * The subset of `$targets` that actually applies: a comment target behaves
     * exactly like the structured "Requested price" field, and the structured
     * field wins unless this is a renegotiation round (`change_requested`),
     * where the newer comment ask wins. Targets naming a line the quote does
     * not have fall away with it.
     *
     * @param array<string, float> $targets as returned by extract()
     *
     * @return array<string, float>
     */
    public function adoptedBy(QuoteSnapshot $snapshot, array $targets): array
    {
        $commentWins = $snapshot->lifecycle->stateTechnicalName === 'change_requested';
        $adopted = [];

        foreach ($snapshot->lines as $line) {
            $target = $targets[$line->lineItemId()] ?? null;

            if ($target !== null && ($commentWins || $line->requestedUnitPrice === null)) {
                $adopted[$line->lineItemId()] = $target;
            }
        }

        return $adopted;
    }
}
