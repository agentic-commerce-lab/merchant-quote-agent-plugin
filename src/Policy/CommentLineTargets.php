<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/**
 * Extracts the per-line target-price asks from a comment interpretation,
 * keyed by line item id.
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
}
