<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Check\ProtocolViolation;

final readonly class EmissionOutcome
{
    private function __construct(
        public EmissionStatus $status,
        public ?Act $act = null,
        public ?ProtocolViolation $violation = null,
    ) {}

    public static function inert(): self
    {
        return new self(EmissionStatus::Inert);
    }

    public static function unchanged(): self
    {
        return new self(EmissionStatus::Unchanged);
    }

    public static function violation(ProtocolViolation $violation): self
    {
        return new self(EmissionStatus::Violation, violation: $violation);
    }

    public static function emitted(Act $act): self
    {
        return new self(EmissionStatus::Emitted, act: $act);
    }

    public static function failed(): self
    {
        return new self(EmissionStatus::Failed);
    }
}
