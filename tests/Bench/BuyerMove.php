<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

/**
 * A synthetic buyer's reply to one negotiation round.
 *
 * Two fields, so an ordinary public promoted constructor is used directly
 * (the parameter-count gate only bites past five — see
 * src/Policy/Data/ArrayMapper.php for where this codebase actually reshapes
 * constructors to satisfy it). Built exclusively through the named
 * constructors below, which keep `comment` correct for each kind: null for
 * accept/walk, required for counter.
 */
final readonly class BuyerMove
{
    public function __construct(
        public BuyerMoveKind $kind,
        public ?string $comment = null,
    ) {}

    public static function accept(): self
    {
        return new self(BuyerMoveKind::Accept);
    }

    public static function walk(): self
    {
        return new self(BuyerMoveKind::Walk);
    }

    public static function counter(string $comment): self
    {
        return new self(BuyerMoveKind::Counter, $comment);
    }
}
