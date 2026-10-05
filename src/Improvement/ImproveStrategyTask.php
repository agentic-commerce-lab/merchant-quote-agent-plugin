<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Daily, with the merchant's cadence enforced inside the run rather than here
 * -- see ImprovementWindow for why the interval is not written to core's
 * scheduled_task table.
 *
 * Like every scheduled task, this needs a worker, or the Administration open.
 * A shop with neither runs no improvement at all, and the admin's empty state
 * has to say so rather than implying a quiet night.
 */
class ImproveStrategyTask extends ScheduledTask
{
    #[\Override]
    public static function getTaskName(): string
    {
        return 'merchant_quote_agent.improve_strategy';
    }

    #[\Override]
    public static function getDefaultInterval(): int
    {
        return self::DAILY;
    }

    #[\Override]
    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }
}
