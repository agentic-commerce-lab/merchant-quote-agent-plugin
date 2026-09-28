<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * Draft Mode's proposal lives in a DAL version of the quote — the same
 * mechanism SwagCommercial's own admin quote editor drafts in — so it is
 * priced by Shopware's real recalculation while the live quote, and so the
 * buyer, sees nothing until the merchant sends it.
 */
interface QuoteDraftVersionsInterface
{
    /** @return string the new version's id */
    public function create(string $quoteId): string;

    /**
     * Whether both the version and this quote's row in that version exist.
     * A versioned DAL read with no quote row falls back to the live quote,
     * so a partially deleted draft must not be sent. False for invalid ids.
     */
    public function exists(string $quoteId, string $versionId): bool;

    /** @throws DraftVersionUnavailable|NotADraftVersion */
    public function gateway(string $versionId): QuoteGatewayInterface;

    /**
     * Row-locks the live quote and its lines until the caller's transaction
     * ends, so a merchant edit cannot land between Send's final staleness
     * check and its merge. Only meaningful inside a transaction.
     */
    public function lockLive(string $quoteId): void;

    /**
     * Replays the version onto the live quote; the version is gone afterwards.
     *
     * @throws NotADraftVersion
     */
    public function merge(string $versionId): void;

    /**
     * Discards the version. A version that is already gone is not an error.
     *
     * @throws NotADraftVersion
     */
    public function delete(string $quoteId, string $versionId): void;
}
