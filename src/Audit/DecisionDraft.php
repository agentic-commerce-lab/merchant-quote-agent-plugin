<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/**
 * The record under construction. Public and mutable on purpose: stages append
 * to it as the pass runs, so a pass that dies halfway still carries everything
 * up to the point it died.
 *
 * Deliberately dumb — no behaviour, no validation. DecisionRecorder owns the
 * lifecycle and DecisionRecordWriter owns the mapping.
 *
 * Mirrors QuoteDecisionRecord's columns one-for-one, minus `id` (the writer
 * generates it), minus `terminalState`/`terminalAt` (reserved, never written
 * by this issue), plus `startedAt` (a stopwatch the writer excludes from the
 * payload).
 *
 * @mago-expect lint:too-many-properties
 * The gate fires above 10 and these properties mirror a table's columns
 * one-for-one. Grouping them would put a translation layer between the draft
 * and the row for no gain.
 */
final class DecisionDraft
{
    public string $quoteId = '';

    public ?string $quoteNumber = null;

    public ?string $salesChannelId = null;

    public ?string $currencyIso = null;

    public ?string $triggerReason = null;

    /** The crash-budget counter at pass start, not a delivery number: a thrown pass that is redelivered records 0 again. */
    public ?int $attempt = null;

    public ?string $revisionVersionId = null;

    public ?\DateTimeImmutable $revisionUpdatedAt = null;

    public ?string $band = null;

    public ?string $outcome = null;

    public ?string $escalationReason = null;

    public ?float $discountPercentGranted = null;

    public ?float $maxDiscountPercent = null;

    public ?float $totalNetBefore = null;

    public ?float $totalNetAfter = null;

    public ?string $model = null;

    public ?string $modelHost = null;

    public ?string $extractPromptHash = null;

    public ?string $negotiatePromptHash = null;

    public ?string $replyPromptHash = null;

    public ?int $promptTokens = null;

    public ?int $completionTokens = null;

    /** Excludes a failed retry attempt's time — see `durationMs` for the pass's total wall-clock. */
    public ?int $modelLatencyMs = null;

    public ?int $durationMs = null;

    public ?bool $authorized = null;

    public ?bool $verified = null;

    public ?string $errorClass = null;

    /** @var array<string, mixed>|null */
    public ?array $interpretedAsks = null;

    public ?string $rawProposal = null;

    /** @var list<string>|null */
    public ?array $violations = null;

    /** @var list<string>|null */
    public ?array $writes = null;

    /** @var list<array<string, string>>|null */
    public ?array $errorChain = null;

    public ?string $buyerComment = null;

    public float $startedAt = 0.0;
}
