<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Thin shell around {@see ImprovementGenerator}; the logic lives there because
 * it is testable there without a kernel.
 *
 * Mirrors GenerateInsightsTaskHandler exactly, including taking the clock as
 * a fresh `DateTimeImmutable` at call time rather than as an injected
 * service: the window's end must be the moment the run starts, and a handler
 * that held a clock from container build time would take it from whenever
 * the worker booted.
 */
#[AsMessageHandler(handles: ImproveStrategyTask::class)]
final class ImproveStrategyTaskHandler extends ScheduledTaskHandler
{
    /** @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ImprovementGenerator $generator,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    /**
     * @throws \Throwable propagated from ImprovementGenerator::generate(), so
     *     the scheduled-task executor's own retry/reschedule machinery sees a
     *     failed run rather than a silently swallowed one.
     */
    #[\Override]
    public function run(): void
    {
        $this->generator->generate(new \DateTimeImmutable());
    }
}
