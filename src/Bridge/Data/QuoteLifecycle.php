<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

final readonly class QuoteLifecycle
{
    /**
     * @param array<string, mixed> $customFields
     *
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and every
     * construction site names its arguments (QuoteSnapshotReader, the test
     * fixtures), so the call-site complexity this rule exists to catch does
     * not arise here — QuoteComment's reason, verbatim.
     */
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
         * human sending an answer; PendingEscalation::awaitsAHuman() reads it.
         */
        public ?string $lastAdminTransitionTo = null,
        /**
         * When a human merchant last wrote a comment on this quote, or null if
         * none ever did. Transport only — QuoteCommentMapper::newestMerchantAt()
         * fills it from the comments the snapshot already loads, and
         * PendingEscalation::awaitsAHuman() counts it as an answer only while
         * the quote sits in `replied`.
         */
        public ?\DateTimeImmutable $lastAdminCommentAt = null,
    ) {}
}
