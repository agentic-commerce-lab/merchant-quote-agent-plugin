<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\History;

use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteHistoryEntry;
use MerchantQuoteAgentPlugin\Bridge\Data\History\QuoteStats;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * The company's quote record, read through the generic DAL. No SwagCommercial
 * class is named (ADR 0001): `quote.repository` arrives by string id and fields
 * come off Entity::get().
 *
 * `converted` and `lost` are read off the QUOTE — its `orderId` and its state —
 * rather than off our decision table, because both are authoritative and both
 * exist for quotes the agent never touched. Our records supply only what only
 * they know: authorized proposal passes and recorded per-pass price reductions.
 */
final readonly class QuoteHistoryReads
{
    /** How many past quotes the model is shown. Enough to see a pattern, small enough to stay a prompt block. */
    private const LIMIT = 25;

    /** A quote in one of these ended without a deal. Mirrors TerminalOutcomeSubscriber::TERMINAL_STATES minus `accepted`. */
    private const LOST_STATES = ['declined', 'expired', 'cancelled', 'withdrawn'];

    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> $quotes */
    public function __construct(
        private EntityRepository $quotes,
        private DecisionAggregate $decisions,
    ) {}

    /**
     * @return list<QuoteHistoryEntry>
     *
     * @throws CrossCustomerRead
     * @throws \Doctrine\DBAL\Exception
     */
    public function entries(CustomerScope $scope): array
    {
        $quotes = $this->load($scope);
        $rollup = $this->decisions->forQuotes(array_map(
            static fn(Entity $q): string => (string) $q->get('id'),
            $quotes,
        ));

        return array_map(static function (Entity $quote) use ($rollup): QuoteHistoryEntry {
            $createdAt = $quote->get('createdAt');

            return new QuoteHistoryEntry(
                quoteNumber: (string) $quote->get('quoteNumber'),
                createdAt: $createdAt instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($createdAt)
                    : null,
                amountNet: (float) $quote->get('amountNet'),
                state: self::stateTechnicalName($quote),
                converted: $quote->get('orderId') !== null,
                grantedDiscountPercent: $rollup->grantedByQuote[(string) $quote->get('id')] ?? null,
                currencyIso: HistoryCurrency::of($quote),
            );
        }, $quotes);
    }

    /**
     * @throws CrossCustomerRead
     * @throws \Doctrine\DBAL\Exception
     */
    public function stats(CustomerScope $scope): QuoteStats
    {
        $quotes = $this->load($scope);
        $ids = array_map(static fn(Entity $q): string => (string) $q->get('id'), $quotes);
        $rollup = $this->decisions->forQuotes($ids);

        $converted = 0;
        $lost = 0;
        $acceptedWithOffer = 0;

        foreach ($quotes as $quote) {
            $name = self::stateTechnicalName($quote);

            $converted += (int) ($quote->get('orderId') !== null);
            $lost += (int) \in_array($name, self::LOST_STATES, strict: true);

            // Count distinct accepted quotes that had an authorized proposal pass.
            // Authorization does not prove the proposal was delivered.
            $acceptedWithOffer += (int) (
                $name === 'accepted'
                && \in_array((string) $quote->get('id'), $rollup->quoteIdsWithOffers, strict: true)
            );
        }

        return new QuoteStats(
            seen: \count($quotes),
            converted: $converted,
            lost: $lost,
            offersMade: $rollup->offersMade,
            offersAccepted: $acceptedWithOffer,
            lastGrantedDiscountPercent: $rollup->lastGrantedDiscountPercent,
        );
    }

    /**
     * @return list<Entity>
     *
     * @throws CrossCustomerRead
     */
    private function load(CustomerScope $scope): array
    {
        $criteria = $scope->criteria('customerId');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('currency');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(self::LIMIT);

        $quotes = [];

        foreach ($this->quotes->search($criteria, $scope->context())->getEntities() as $quote) {
            if (!$quote instanceof Entity) {
                continue;
            }

            $number = (string) $quote->get('quoteNumber');
            $seen = $quote->get('customerId');
            $scope->verify(\is_string($seen) ? $seen : null, 'quote ' . $number);
            $quotes[] = $quote;
        }

        return $quotes;
    }

    private static function stateTechnicalName(Entity $quote): string
    {
        $state = $quote->get('stateMachineState');

        return $state instanceof Entity ? (string) $state->get('technicalName') : '';
    }
}
