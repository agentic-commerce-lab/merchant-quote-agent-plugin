<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

/** An exit that produced no decision row (run-trace spec §2). */
enum SkipReason: string
{
    case NoGateway = 'no_gateway';
    case LockBusy = 'lock_busy';
    case QuoteNotFound = 'quote_not_found';
    case StaleTrigger = 'stale_trigger';
    case NothingNew = 'nothing_new';
    case NoPipeline = 'no_pipeline';
    case CrashBudget = 'crash_budget';
    case AttemptWriteFailed = 'attempt_write_failed';
    case TerminalState = 'terminal_state';
    case KillSwitch = 'kill_switch';
    case RefusalWriteFailed = 'refusal_write_failed';
    case ObservationFailed = 'observation_failed';
}
