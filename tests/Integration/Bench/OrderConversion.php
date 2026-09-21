<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

/**
 * What trying to convert an accepted quote into a real order came back as.
 *
 * Grouped out of NegotiationResult's own constructor for the same reason
 * PassContext exists (see its docblock): NegotiationResult was already at
 * four fields, and a bare fifth and sixth scalar would put it at the
 * five-parameter cap with no room left. Exactly one of the two is non-null
 * whenever a conversion was actually attempted (`BenchNegotiation::run()`
 * only ever attempts one on an Accept move); both are null when nothing was
 * attempted at all — an escalated quote, a walk, or a round-cap timeout.
 */
final readonly class OrderConversion
{
    public function __construct(
        public ?string $orderId,
        public ?string $orderFailure,
    ) {}

    /** No conversion was ever attempted: the terminal move was not Accept. */
    public static function notAttempted(): self
    {
        return new self(null, null);
    }
}
