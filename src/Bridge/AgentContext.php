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
 * Its ceiling is not the process boundary — it is the Context instance. STATE
 * survives `Context::scope()` (QuoteCommenter wraps its write in one and the
 * state comes through unchanged), but it does not survive
 * `Context::createWithVersionId()`, which re-versions to a fresh `Context` and
 * copies over only `scope` and `extensions` (Framework/Context.php:173) —
 * `states` is not among them. SwagCommercial calls exactly that, in the same process,
 * milliseconds after our write: QuoteHistoryWriter mirrors every Live-version
 * quote_comment into the quote's snapshot version, and the mirrored write's
 * `quote_comment.written` event carries a Context that has lost STATE.
 * `hasState(STATE)` is therefore only reliable on the Context the write itself
 * used — a caller reading it off a later-derived Context (as any re-versioned
 * copy is) sees it missing even for an agent write. AgentContextTest proves
 * both halves against the real write path.
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

    /**
     * The agent's context re-versioned onto a DAL version — Draft Mode writes
     * its offer into one. The state is added AFTER createWithVersionId(),
     * which drops states (see above), or the version's writes would read as
     * a stranger's.
     */
    public static function forVersion(string $versionId): Context
    {
        $context = Context::createDefaultContext()->createWithVersionId($versionId);
        $context->addState(self::STATE);

        return $context;
    }
}
