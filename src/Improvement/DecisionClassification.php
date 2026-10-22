<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * The closed-vocabulary "what happened" fields of one harvested decision --
 * split out of HarvestedDecision so its own constructor stays under the
 * parameter-count gate. Every field here is an enum's wire value or null,
 * never free text; see HarvestedDecision's docblock for the privacy argument.
 */
final readonly class DecisionClassification
{
    public function __construct(
        public ?string $band,
        public ?string $outcome,
        public ?string $escalationReason,
        public ?string $terminalState,
    ) {}
}
