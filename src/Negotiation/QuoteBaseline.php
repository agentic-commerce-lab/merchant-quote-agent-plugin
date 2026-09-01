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

        $lines = [];

        foreach ($rows as $row) {
            $line = BaselineRow::read($row);

            if ($line === null) {
                return null;
            }

            $lines[] = $line;
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
}
