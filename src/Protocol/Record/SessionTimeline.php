<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/**
 * The `session_timeline` block of the audit log (spec section 10), split out
 * of AuditLog because it is its own small pass over the chain: init, ack,
 * first offer and terminal timestamps, plus the duration between the first
 * and the generation time.
 */
final readonly class SessionTimeline
{
    public string $sessionInitAt;

    public ?string $sessionAckAt;

    public ?string $firstOfferAt;

    public string $terminalStateAt;

    public int $totalDurationSeconds;

    /** @param list<Act> $acts */
    public function __construct(array $acts, OfferSelection $selection, string $generatedAt)
    {
        $this->sessionInitAt = OptionalAct::stringOr(
            $selection->firstAct,
            static fn(Act $act): string => $act->timestamp(),
            $generatedAt,
        );
        $this->sessionAckAt = self::firstTimestampOfType($acts, 'session_ack');
        $this->firstOfferAt = OptionalAct::stringOrNull(
            $selection->firstOffer,
            static fn(Act $act): string => $act->timestamp(),
        );
        $this->terminalStateAt = OptionalAct::stringOr(
            $selection->lastAct,
            static fn(Act $act): string => $act->timestamp(),
            $generatedAt,
        );
        $this->totalDurationSeconds = self::durationSeconds(OptionalAct::stringOrNull(
            $selection->firstAct,
            static fn(Act $act): string => $act->timestamp(),
        ), $generatedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'session_init_at' => $this->sessionInitAt,
            'session_ack_at' => $this->sessionAckAt,
            'first_offer_at' => $this->firstOfferAt,
            'terminal_state_at' => $this->terminalStateAt,
            'total_duration_seconds' => $this->totalDurationSeconds,
        ];
    }

    /** @param list<Act> $acts */
    private static function firstTimestampOfType(array $acts, string $type): ?string
    {
        foreach ($acts as $act) {
            if ($act->messageType() === $type) {
                return $act->timestamp();
            }
        }

        return null;
    }

    private static function durationSeconds(?string $from, string $to): int
    {
        if ($from === null) {
            return 0;
        }

        $start = strtotime($from);
        $end = strtotime($to);
        if ($start === false || $end === false) {
            return 0;
        }

        return max(0, $end - $start);
    }
}
