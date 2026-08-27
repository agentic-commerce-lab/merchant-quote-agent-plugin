<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use Shopware\Core\Framework\Context;

/**
 * The only class in this plugin that reaches SwagCommercial. Its @internal
 * dependencies are confined to Bridge\Commercial adapters and listed there.
 */
final readonly class SwagCommercialQuoteGateway implements QuoteGatewayInterface
{
    public function __construct(
        private QuoteSnapshotReader $reader,
        private QuoteWriters $writers,
    ) {}

    #[\Override]
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot
    {
        return $this->reader->read($quoteId, $version, Context::createDefaultContext());
    }

    /** @param list<QuoteLineItemChange> $changes */
    #[\Override]
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void
    {
        $context = Context::createDefaultContext();
        $this->assertRevision($quoteId, $expected, $context);
        $this->writers->lineItems->write($changes, $context);
    }

    #[\Override]
    public function addProduct(string $quoteId, string $productId, int $quantity): void
    {
        $this->writers->productAdder->addProduct($quoteId, $productId, $quantity, Context::createDefaultContext());
    }

    #[\Override]
    public function recalculate(string $quoteId): void
    {
        $this->writers->recalculator->recalculate($quoteId, Context::createDefaultContext());
    }

    /** @throws QuoteNotFoundException|QuoteRevisionMismatch */
    private function assertRevision(string $quoteId, ?QuoteRevision $expected, Context $context): void
    {
        if ($expected === null) {
            return;
        }

        $current = $this->reader->read($quoteId, QuoteVersion::Live, $context)->revision;

        if (!$current->matches($expected)) {
            throw QuoteRevisionMismatch::forId($quoteId);
        }
    }

    /** @throws QuoteNotFoundException|QuoteRevisionMismatch */
    #[\Override]
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void
    {
        $context = Context::createDefaultContext();
        $this->assertRevision($quoteId, $expected, $context);
        $this->writers->quote->write($quoteId, $update, $context);
    }

    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        throw new \LogicException('Not implemented until plan Task 8.');
    }

    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        throw new \LogicException('Not implemented until plan Task 8.');
    }
}
