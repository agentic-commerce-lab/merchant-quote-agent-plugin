<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;

/**
 * Records the writes the handler makes and serves a queue of snapshots, so a
 * test can make the second read differ from the first the way a real servicing
 * pass does.
 */
final class FakeQuoteGateway implements QuoteGatewayInterface
{
    /** @var list<array<string, mixed>> */
    public array $customFieldWrites = [];

    /** @var list<QuoteUpdate> every quote update in call order, so a test can assert what was written and not only that something was */
    public array $quoteUpdates = [];

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $comments = [];

    /** @var list<QuoteLineItemChange> */
    public array $lineItemChanges = [];

    public ?QuoteRevision $firstExpectedRevision = null;

    public ?\Throwable $transitionThrows = null;

    public ?\Throwable $updateThrows = null;

    /** @var list<QuoteTransition> */
    public array $transitions = [];

    /** @param list<QuoteSnapshot> $snapshots served in order; the last one repeats */
    public function __construct(
        private array $snapshots,
        private readonly bool $quoteMissing = false,
    ) {}

    /**
     * Swap the queue after construction, for a harness that decides its totals
     * later than it builds its gateway.
     *
     * @param list<QuoteSnapshot> $snapshots
     */
    public function replaceSnapshots(array $snapshots): void
    {
        $this->snapshots = $snapshots;
    }

    #[\Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        if ($this->quoteMissing) {
            throw QuoteNotFoundException::forId($quoteId);
        }

        $this->calls[] = 'fetchSnapshot';

        // Narrowed with assert() rather than indexed bare: the constructor's
        // `list<QuoteSnapshot>` type does not statically forbid an empty list,
        // so mago sees array_shift()/[0] as possibly null without this.
        $snapshot = \count($this->snapshots) > 1 ? array_shift($this->snapshots) : $this->snapshots[0] ?? null;
        \assert($snapshot instanceof QuoteSnapshot, description: 'The snapshot queue was constructed empty.');

        return $snapshot;
    }

    #[\Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        if ($this->updateThrows !== null) {
            throw $this->updateThrows;
        }

        $this->calls[] = 'updateQuote';
        $this->quoteUpdates[] = $update;
        $this->firstExpectedRevision ??= $expected;

        if ($update->customFields !== null) {
            $this->customFieldWrites[] = $update->customFields;
        }
    }

    /** @param list<QuoteLineItemChange> $changes */
    #[\Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        $this->calls[] = 'updateLineItems';
        $this->firstExpectedRevision ??= $expected;

        foreach ($changes as $change) {
            $this->lineItemChanges[] = $change;
        }
    }

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void {}

    #[\Override]
    public function recalculate(string $quoteId): void
    {
        $this->calls[] = 'recalculate';
    }

    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        $this->calls[] = 'addComment';
        $this->comments[] = $comment;
    }

    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        $this->calls[] = 'transition';
        $this->transitions[] = $action;

        if ($this->transitionThrows !== null) {
            $exception = $this->transitionThrows;
            $this->transitionThrows = null;

            /** @var \Throwable $exception */
            throw $exception;
        }
    }
}
