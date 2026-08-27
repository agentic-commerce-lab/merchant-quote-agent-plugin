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
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * The only class in this plugin that reaches SwagCommercial. Its @internal
 * dependencies are confined to Bridge\Commercial adapters and listed there.
 */
final readonly class SwagCommercialQuoteGateway implements QuoteGatewayInterface
{
    public function __construct(
        private QuoteSnapshotReader $reader,
        private QuoteWriters $writers,
        private QuoteLifecycleWriters $lifecycle,
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

    /** @throws UnsupportedProductException */
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

    /**
     * A quote id off a message may have been deleted since it was queued, and
     * QuoteCommenter does not check: it inserts the row and lets MySQL reject
     * it on `fk.quote_comment.quote_id`, so the caller sees Doctrine's
     * ForeignKeyConstraintViolationException. Deliberately NOT translated to
     * QuoteNotFoundException — the spec scopes that exception to
     * `fetchSnapshot`, and translating would either put a doctrine/dbal import
     * into production code (an undeclared dependency) or cost a redundant read
     * on every comment. AddCommentTest pins the behaviour so the choice is
     * visible rather than accidental.
     */
    /** @throws QuoteNotFoundException */
    #[\Override]
    public function addComment(string $quoteId, string $comment): void
    {
        $context = Context::createDefaultContext();
        // QuoteCommenter inserts blindly and lets the quote_comment foreign key
        // reject an unknown id, which would leak a Doctrine exception through this
        // interface. One redundant read keeps the isolation the interface promises
        // without declaring doctrine/dbal.
        $this->reader->read($quoteId, QuoteVersion::Live, $context);
        $this->lifecycle->comments->comment($quoteId, $comment, $context);
    }

    /**
     * No revision precondition, unlike the write methods above: a transition is
     * a state-machine action rather than a field write, and SwagCommercial's own
     * admin controller does not gate it either. An action the current state does
     * not offer is rejected by the state machine, which is the check that
     * matters here.
     *
     * An id that resolves to no quote surfaces as Shopware's own
     * StateMachineException ("Unable to read entity quote with id …"), not as
     * QuoteNotFoundException: it is already a typed Shopware error saying
     * exactly that, and narrowing it would mean matching on its error code.
     *
     * @throws IllegalTransitionException
     */
    #[\Override]
    public function transition(string $quoteId, QuoteTransition $action): void
    {
        $this->lifecycle->state->transition($quoteId, $action, Context::createDefaultContext());
    }
}
