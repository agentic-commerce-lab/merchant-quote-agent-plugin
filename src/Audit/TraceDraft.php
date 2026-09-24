<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * One event of the pass under way. Buffered on the DecisionDraft and written
 * by DecisionRecordWriter after the decision row, so a pass's events share
 * its lifecycle: a pass that dies halfway still leaves what it had recorded,
 * and nothing is written for a pass that never opened.
 *
 * `appendTo()` is where TraceKind::metaKeys() is enforced: every declared key
 * is present (null when the site had no value), nothing undeclared survives.
 */
final readonly class TraceDraft
{
    /**
     * @param array<string, mixed> $meta
     * @param array<array-key, mixed>|null $content
     */
    public function __construct(
        public TraceKind $kind,
        public int $position,
        public \DateTimeImmutable $occurredAt,
        public array $meta,
        public ?array $content,
    ) {}

    /**
     * @param array<string, mixed> $meta
     * @param array<array-key, mixed>|null $content
     */
    public static function appendTo(DecisionDraft $draft, TraceKind $kind, array $meta, ?array $content): void
    {
        [$content, $cut] = $content === null ? [null, []] : TracePayload::capped(TracePayload::of($content));
        $declared = array_fill_keys($kind->metaKeys(), null);
        $meta = [...$declared, ...array_intersect_key($meta, $declared)];

        if ($cut !== []) {
            $meta['truncated'] = $cut;
        }

        $draft->trace[] = new self($kind, \count($draft->trace), new \DateTimeImmutable(), $meta, $content);
    }

    /** @return array<string, mixed> one create payload for `merchant_quote_agent_trace` */
    public function payload(DecisionDraft $draft): array
    {
        return [
            'id' => Uuid::randomHex(),
            'decisionId' => $draft->id,
            'quoteId' => $draft->quoteId === '' ? null : $draft->quoteId,
            'customerId' => $draft->customerId,
            'kind' => $this->kind->value,
            'position' => $this->position,
            'occurredAt' => $this->occurredAt,
            'meta' => $this->meta,
            'content' => $this->content,
        ];
    }
}
