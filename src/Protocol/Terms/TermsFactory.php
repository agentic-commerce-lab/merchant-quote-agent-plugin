<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Terms;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Crypto\ProtocolHash;

/**
 * Shopware quote → A2CN `terms` (spec v0.2.0 base schema).
 *
 * Every amount is integer minor units. `total` is authoritative and
 * `total_value = sum(total)` reconciles with what the buyer is invoiced;
 * `unit_price` is derived and need NOT multiply out — see the spec's
 * determinism rule 3.
 *
 * The bridge snapshot is already net (QuoteLineNet takes each line's own
 * calculated taxes off, and QuoteTotals::totalNet comes from `amountNet`), so
 * there is no tax arithmetic here. `shopware_net_total_minor` carries the
 * quote's own net total beside the summed line totals, so a divergence — a
 * quote-level discount, a rounding disagreement — is visible to a reader
 * instead of hidden inside one number.
 *
 * No non-price terms: this plugin does not persist payment term, net days,
 * deposit or lead time (#11), and a signed promise nothing downstream honours
 * is worse than an omitted field.
 */
final readonly class TermsFactory
{
    private const TAX_STATUS = 'net';

    /** Bridge\Data\QuoteLineIdentity carries no unit, so every line is 'piece'. */
    private const DEFAULT_UNIT = 'piece';

    public function __construct(
        private ProtocolHash $hash,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws NonFiniteAmount
     */
    public function fromSnapshot(QuoteSnapshot $snapshot): array
    {
        $lineItems = array_map($this->lineItem(...), $snapshot->content->lines);

        $totalValue = 0;
        foreach ($lineItems as $lineItem) {
            $totalValue += $lineItem['total'];
        }

        return [
            'total_value' => $totalValue,
            'currency' => $snapshot->identity->currencyIso,
            'line_items' => array_values($lineItems),
            'custom_terms' => [
                'tax_status' => self::TAX_STATUS,
                'quote_number' => $snapshot->identity->quoteNumber,
                'shopware_net_total_minor' => MinorUnits::from($snapshot->totals->totalNet),
            ],
        ];
    }

    /**
     * "Terms changed" must mean exactly "the signed bytes would change", so the
     * comparison runs over the canonical form rather than PHP equality — key
     * order differs freely between a rebuilt array and one read back off an act.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $current
     */
    public function unchanged(?array $previous, array $current): bool
    {
        if ($previous === null) {
            return false;
        }

        return $this->hash->canonical($previous) === $this->hash->canonical($current);
    }

    /**
     * @return array{id: string, description: string, quantity: int, unit: string, unit_price: int, total: int}
     *
     * @throws NonFiniteAmount
     */
    private function lineItem(QuoteLineSnapshot $line): array
    {
        $total = MinorUnits::from($line->totalNet);
        $quantity = $line->quantity;

        return [
            'id' => $line->identity->lineItemId,
            'description' => $line->identity->label ?? $line->identity->lineItemId,
            'quantity' => $quantity,
            'unit' => self::DEFAULT_UNIT,
            'unit_price' => $quantity === 0 ? $total : (int) round($total / $quantity),
            'total' => $total,
        ];
    }
}
