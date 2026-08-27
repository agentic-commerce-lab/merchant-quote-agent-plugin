<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\Framework\Context;

/**
 * Every write the agent makes runs under a Context carrying STATE, so the
 * servicing trigger can tell its own writes from a buyer's and skip them.
 *
 * This replaces the author check the original design assumed: #3 measured
 * `createdById`, `customerId` and `employeeId` as ALL null on an agent comment,
 * and 42 of the test shop's 118 existing comments are author-less too — so null
 * does not mean "ours". A Context state does, and it covers comments, state
 * transitions and line-item writes with one check instead of one per surface.
 *
 * Its ceiling is that it only works in-process. That is where our own writes
 * happen: the message handler and the trigger run in the same worker. A write
 * arriving from outside this process is a buyer write by definition.
 *
 * Lives in Bridge rather than Servicing because the layer order is policy,
 * bridge, servicing: the bridge stamps, servicing reads.
 */
final class AgentContext
{
    public const STATE = 'merchant-quote-agent';

    private function __construct() {}

    public static function create(): Context
    {
        $context = Context::createDefaultContext();
        $context->addState(self::STATE);

        return $context;
    }
}
