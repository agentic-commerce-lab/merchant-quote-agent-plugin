<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot as BridgeSnapshot;

/**
 * The quote custom field that anchors per-line offers (#49, formerly #2(a)).
 *
 * Before this existed, the reference a per-line offer is bounded against was
 * re-captured every round, so round two was measured against round one's
 * already-reduced prices and compounded straight past maxDiscountPercent —
 * with the authorizer and the verifier both reporting clean.
 *
 * The key sits alongside merchant_quote_agent_serviced / _escalated /
 * _attempts and is shallow-merged by the gateway, so it cannot collide with
 * them or with the A2CN act chain.
 */
final class QuoteBaseline
{
    public const KEY = 'merchant_quote_agent_baseline';

    private function __construct() {}

    public static function read(BridgeSnapshot $snapshot): ?QuoteBaselineLines
    {
        $raw = $snapshot->lifecycle->customFields[self::KEY] ?? null;

        if (!\is_array($raw)) {
            return null;
        }

        $totalNet = $raw['totalNet'] ?? null;
        $rows = $raw['lines'] ?? null;

        if (!\is_numeric($totalNet) || !\is_array($rows) || $rows === []) {
            return null;
        }

        $lines = BaselineRow::readAll($rows);

        if ($lines === null) {
            return null;
        }

        return new QuoteBaselineLines((float) $totalNet, $lines);
    }

    /**
     * The custom-field fragment for QuoteUpdate. Takes the whole snapshot
     * because the total is as load-bearing as the lines.
     *
     * @return array<string, mixed>
     */
    public static function stamp(BridgeSnapshot $snapshot): array
    {
        return [
            self::KEY => [
                'totalNet' => $snapshot->totals->totalNet,
                'lines' => array_map(BaselineRow::write(...), $snapshot->content->lines),
            ],
        ];
    }

    /**
     * `stamp()` when the quote has no baseline, the baseline EXTENDED with any
     * line it does not yet know when it has one, and an empty fragment when it
     * already knows them all (#54) — spread-friendly, so a caller building a
     * customFields array needs no conditional of its own.
     *
     * Extension is one-way and never revalues: a line the baseline already
     * holds keeps its stored price forever, so the anchor still cannot move.
     * Only a row with no entry at all is appended, at the price it carries on
     * this pass — which is its price before any agent concession, because both
     * callers compute this from a snapshot read BEFORE the pass writes.
     *
     * Kept as one function with two callers on purpose: OfferApplier::write()
     * and ServiceQuoteHandler::claimAttempt() both used to ask only "is there
     * a baseline?", and neither asked whether it covered the lines that are on
     * the quote now.
     *
     * @return array<string, mixed>
     */
    public static function stampOrExtend(BridgeSnapshot $snapshot): array
    {
        $baseline = self::read($snapshot);

        if ($baseline === null) {
            return self::stamp($snapshot);
        }

        $extended = $baseline->extendedWith(SnapshotAdapter::toPolicy($snapshot)->lines);

        if ($extended === $baseline) {
            return [];
        }

        return [
            self::KEY => [
                'totalNet' => $extended->totalNet,
                'lines' => array_map(BaselineRow::writeStored(...), $extended->lines),
            ],
        ];
    }
}
