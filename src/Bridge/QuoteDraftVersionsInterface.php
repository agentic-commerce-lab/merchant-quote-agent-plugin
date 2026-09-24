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
     * Whether the version still has its row. Ask before reading a stored id:
     * a versioned DAL read of a version whose rows are gone falls back to the
     * live quote instead of failing, so a draft that no longer exists would
     * read as the live prices. False for anything that is not a uuid.
     */
    public function exists(string $versionId): bool;

    /** @throws DraftVersionUnavailable|NotADraftVersion */
    public function gateway(string $versionId): QuoteGatewayInterface;

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
