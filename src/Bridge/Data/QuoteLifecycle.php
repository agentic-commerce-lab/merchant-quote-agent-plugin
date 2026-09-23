<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteLifecycle
{
    /** @param array<string, mixed> $customFields */
    public function __construct(
        public string $stateTechnicalName,
        public ?\DateTimeImmutable $expiresAt = null,
        public array $customFields = [],
        /**
         * When a human merchant last moved this quote through its state
         * machine, or null if none ever did. Transport only — see
         * MerchantActionReader for what fills it and Negotiation\MerchantHandover
         * for the decision it feeds, which also weighs the merchant's comments.
         */
        public ?\DateTimeImmutable $lastAdminTransitionAt = null,
        /**
         * The state that transition moved the quote into. `replied` is a
         * human sending an answer; QuoteEscalator::awaitsAHuman() reads it.
         */
        public ?string $lastAdminTransitionTo = null,
    ) {}
}
