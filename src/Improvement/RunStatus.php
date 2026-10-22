<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * How a nightly run ended.
 *
 * There is deliberately no `not_due` case: a tick that is not due writes
 * nothing at all, so the history holds the nights that did something.
 * `no_data` is different and IS written -- it says we looked at the window and
 * there were no decisions in it, which a merchant needs to be able to tell
 * apart from a worker that never ran.
 */
enum RunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case NoData = 'no_data';
}
