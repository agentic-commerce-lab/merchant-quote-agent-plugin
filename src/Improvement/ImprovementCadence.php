<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/** How often the merchant wants the loop to look. */
enum ImprovementCadence: string
{
    case Daily = 'daily';
    case EveryThreeDays = 'every3days';
    case Weekly = 'weekly';

    public function days(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::EveryThreeDays => 3,
            self::Weekly => 7,
        };
    }

    /**
     * Named fromRaw() rather than from(): a backed enum already HAS from(),
     * and an override with a different contract -- returning a default
     * instead of throwing -- is the kind of surprise a reader does not check
     * for. An unreadable value falls back to Daily rather than throwing,
     * because a typo in system_config must not stop the whole night.
     */
    public static function fromRaw(mixed $raw): self
    {
        return \is_string($raw) ? self::tryFrom($raw) ?? self::Daily : self::Daily;
    }
}
