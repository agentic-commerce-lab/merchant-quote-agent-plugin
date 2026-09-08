<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use Override;

/**
 * Records the quote writes the emitter makes, and can fail the one that
 * matters — the wire append after the mirror succeeded.
 *
 * Implements every method of QuoteGatewayInterface; only updateQuote and
 * fetchSnapshot are exercised.
 */
final class RecordingQuoteGateway implements QuoteGatewayInterface
{
    /** @var list<QuoteUpdate> */
    public array $updates = [];

    public function __construct(
        private readonly bool $failOnUpdate = false,
        private readonly ?QuoteSnapshot $snapshot = null,
    ) {}

    #[Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        return $this->snapshot ?? ProtocolFixtures::snapshot($quoteId);
    }

    #[Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        if ($this->failOnUpdate) {
            throw new \RuntimeException('wire write failed');
        }

        $this->updates[] = $update;
    }

    #[Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function recalculate(string $quoteId): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function addComment(string $quoteId, string $comment): void
    {
        throw new \LogicException('not used');
    }

    #[Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        throw new \LogicException('not used');
    }
}
