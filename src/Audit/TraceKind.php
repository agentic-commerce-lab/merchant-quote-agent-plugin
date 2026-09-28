<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * What one row of `merchant_quote_agent_trace` records. A closed list: the
 * export and the eraser both reason per kind, and an open string would let a
 * new event reach a merchant's file without anyone having decided what it is.
 *
 * `metaKeys()` is the allowlist for `meta`, the half of an event that leaves
 * in every export. TraceDraft keeps exactly these keys and drops anything
 * else, so a new field has to be declared here before it can leave -- the
 * same rule AnonymizedDecision applies to decision columns, enforced at write
 * time instead of at export time. Everything that can carry the buyer's
 * words, a model's words or account data goes into `content` instead.
 *
 * See docs/superpowers/specs/2026-09-23-run-trace-capture-design.md §2.
 */
enum TraceKind: string
{
    case ModelCall = 'model_call';
    case ReplyGuard = 'reply_guard';
    case PolicyVerdict = 'policy_verdict';
    case QuoteBefore = 'quote_before';
    case QuoteAfter = 'quote_after';
    case Skip = 'skip';
    case Http = 'http';
    case AssistantTool = 'assistant_tool';
    case SellerAct = 'seller_act';
    case Rounding = 'rounding';

    /** @return list<string> */
    public function metaKeys(): array
    {
        return match ($this) {
            self::ModelCall => [
                'purpose',
                'requestedModel',
                'servedModel',
                'host',
                'status',
                'httpStatus',
                'latencyMs',
                'promptTokens',
                'completionTokens',
                'cachedTokens',
                'reasoningTokens',
                'finishReason',
                'retries',
                'errorClass',
            ],
            self::ReplyGuard => ['accepted'],
            self::PolicyVerdict => [
                'overall',
                'priceKind',
                'escalationReason',
                'requestedDiscountPercent',
                'discountPercent',
                'perLineAsks',
                'validityDays',
                'counteredRequestPercent',
                'escalationReasons',
            ],
            self::QuoteBefore, self::QuoteAfter => ['lineCount'],
            self::Skip => ['source', 'reason', 'trigger', 'attempt'],
            self::Http => ['route', 'method', 'httpStatus', 'durationMs', 'sessionId', 'errorCode'],
            self::AssistantTool => ['tool', 'status'],
            self::SellerAct => ['sessionId', 'seq', 'actType', 'offerHash', 'result'],
            self::Rounding => ['mode', 'step', 'unrounded', 'rounded', 'skipped'],
        };
    }
}
