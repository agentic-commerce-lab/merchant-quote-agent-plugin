<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/**
 * `createdById` / `customerId` / `employeeId` are how a reader tells a buyer's
 * comment from the agent's own: #3 measured all three as null on an agent
 * comment, and AddCommentTest pins that. `isAuthored()` is the servicing
 * fingerprint's discriminator — see Servicing\ServicingFingerprint.
 */
final readonly class QuoteComment
{
    /**
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and every
     * construction site uses named arguments (see QuoteCommentMapper), so the
     * call-site complexity this rule exists to catch does not arise here.
     */
    public function __construct(
        public string $comment,
        public ?string $lineItemId = null,
        public ?string $createdById = null,
        public ?string $customerId = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?string $employeeId = null,
    ) {}

    public function isAuthored(): bool
    {
        return $this->createdById !== null || $this->customerId !== null || $this->employeeId !== null;
    }
}
