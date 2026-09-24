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
