<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/** What one replayed pass did. Never persisted; aggregated by ReplayScore. */
final readonly class ReplayArm
{
    private function __construct(
        public bool $escalated,
        public ?float $grantedPercent,
        public bool $modelRefused,
        public bool $failed,
    ) {}

    public static function escalated(): self
    {
        return new self(true, null, false, false);
    }

    public static function modelRefused(): self
    {
        return new self(true, null, true, false);
    }

    public static function offered(?float $grantedPercent): self
    {
        return new self(false, $grantedPercent, false, false);
    }

    /** The provider was unreachable. Counted, and kept out of the rates. */
    public static function failed(): self
    {
        return new self(false, null, false, true);
    }
}
