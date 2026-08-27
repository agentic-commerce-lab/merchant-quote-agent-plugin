<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteRevision;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteVersion;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Everything the plugin is allowed to know about SwagCommercial's quotes.
 * No Shopware Context appears here on purpose: building and threading it is
 * the gateway's business, not its callers'.
 */
interface QuoteGatewayInterface
{
    /** @throws QuoteNotFoundException */
    public function fetchSnapshot(string $quoteId, QuoteVersion $version = QuoteVersion::Live): QuoteSnapshot;

    /**
     * @param list<QuoteLineItemChange> $changes
     * @throws QuoteNotFoundException
     * @throws QuoteRevisionMismatch
     */
    public function updateLineItems(string $quoteId, array $changes, ?QuoteRevision $expected = null): void;

    /** @throws QuoteNotFoundException */
    public function addProduct(string $quoteId, string $productId, int $quantity): void;

    /** @throws QuoteNotFoundException */
    public function recalculate(string $quoteId): void;

    /**
     * @throws QuoteNotFoundException
     * @throws QuoteRevisionMismatch
     */
    public function updateQuote(string $quoteId, QuoteUpdate $update, ?QuoteRevision $expected = null): void;

    /**
     * An unknown id is rejected by the `quote_comment` foreign key, not by a
     * bridge-level check, so it surfaces as Doctrine's
     * ForeignKeyConstraintViolationException — NOT QuoteNotFoundException,
     * which the spec scopes to `fetchSnapshot`. See the gateway.
     */
    public function addComment(string $quoteId, string $comment): void;

    /**
     * Rejects an action the quote's current state does not offer, and leaves the
     * quote untouched when it does. An unknown id raises Shopware's own
     * StateMachineException rather than QuoteNotFoundException — see the
     * gateway for why.
     *
     * @throws IllegalTransitionException
     */
    public function transition(string $quoteId, QuoteTransition $action): void;
}
