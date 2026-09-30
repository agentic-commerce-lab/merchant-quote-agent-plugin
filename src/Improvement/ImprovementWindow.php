<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * Whether tonight's tick should do anything, and what it should look at.
 *
 * The cadence is enforced HERE and not by core's scheduler. The task stays
 * DAILY and this returns null until the merchant's interval has passed, for
 * three reasons: the plugin does not write `scheduled_task`, a table it does
 * not own and would have to restore on uninstall; a shop whose worker missed
 * two nights still runs on the next tick; and the window is "since the last
 * completed run" either way, so a missed night is ABSORBED rather than lost.
 *
 * Half-open [from, to), the same convention as the decision export, so two
 * consecutive runs can never read one decision twice.
 */
final readonly class ImprovementWindow
{
    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {}

    public static function due(
        ?\DateTimeImmutable $lastCompletedRun,
        \DateTimeImmutable $now,
        ImprovementCadence $cadence,
    ): ?self {
        $span = \sprintf('P%dD', $cadence->days());
        $earliest = $now->sub(new \DateInterval($span));

        if ($lastCompletedRun === null) {
            return new self($earliest, $now);
        }

        // A $lastCompletedRun in the FUTURE (clock skew, a restored backup)
        // fails this same comparison and comes back not due, rather than due
        // with a window whose $from could land after $now. Next night's tick
        // re-evaluates from the same (still future) timestamp until real
        // time catches up to it -- a night of silence, never a bad window.
        if ($lastCompletedRun > $earliest) {
            return null;
        }

        return new self($lastCompletedRun, $now);
    }
}
