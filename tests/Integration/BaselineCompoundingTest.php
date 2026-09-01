<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use Shopware\Core\Framework\Context;

/**
 * #2(a)'s own fixture requirement, finally exercised: a multi-round per-line
 * negotiation must not drift past the cap relative to the ORIGINAL prices.
 *
 * Before #49 the reference was re-captured each round, so three rounds of
 * "10% off" compounded to roughly 27% with the authorizer and the verifier
 * both reporting clean. This is the test that would have caught that.
 */
final class BaselineCompoundingTest extends IntegrationTestCase
{
    use PipelineFixture;

    public function testThreePerLineRoundsCannotDriftPastTheCap(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');
        $original = $gateway->fetchSnapshot($quoteId);
        $originalPrices = self::unitPricesById($original);
        $originalTotal = $original->totals->totalNet;

        for ($round = 1; $round <= 3; $round++) {
            self::writeBuyerComment($quoteId, sprintf('Round %d: I need a better per-unit price.', $round));

            self::pipelineWith([
                '{"additional_discount_percent": 10}',
                self::modelOffersTenPercentOffEveryLine($gateway->fetchSnapshot($quoteId)),
                'Here is our best price.',
            ])->service(
                $gateway->fetchSnapshot($quoteId),
                $gateway,
                self::enabledSettings(),
                NegotiationFixture::context(),
            );
        }

        $final = $gateway->fetchSnapshot($quoteId);
        $floorFactor = 1 - (self::enabledSettings()->policy->price->maxDiscountPercent / 100);

        foreach ($final->content->lines as $line) {
            $originalPrice = $originalPrices[$line->identity->lineItemId] ?? null;

            if ($originalPrice === null) {
                continue; // Shopware-generated line; the totals assertion covers it.
            }

            self::assertGreaterThanOrEqual(
                round($originalPrice * $floorFactor, 2) - 0.01,
                round($line->unitPriceNet, 2),
                sprintf(
                    'Line "%s" ended at %s, below the %s floor set by the ORIGINAL price %s — '
                    . 'the per-line reference is compounding again.',
                    $line->identity->label ?? $line->identity->lineItemId,
                    $line->unitPriceNet,
                    $originalPrice * $floorFactor,
                    $originalPrice,
                ),
            );
        }

        // `unitPriceNet` is a two-decimal projection of a higher-precision figure
        // (this quote's line reads as 16.8, but the true value behind it is
        // ~16.7985), so `roundedUnit × qty` and Shopware's own `totalNet`
        // legitimately differ — a gap present in the ORIGINAL total too, before
        // any negotiation. A flat cent (right for a single unit, per line above)
        // undercounts once quantity multiplies it, so the total's tolerance
        // grants the same half-cent-per-unit rounding budget once per unit
        // instead of once per line. A real compounding regression (three rounds
        // of 10% off applied instead of one) misses this floor by tens of units
        // of currency, not fractions of a cent, so this cannot mask it.
        $totalQuantity = array_sum(array_map(static fn($line) => $line->quantity, $final->content->lines));

        self::assertGreaterThanOrEqual(
            round($originalTotal * $floorFactor, 2) - (0.01 * $totalQuantity),
            round($final->totals->totalNet, 2),
            'The total drifted below the cap relative to the original total.',
        );
    }

    /** @return array<string, float> */
    private static function unitPricesById(\MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $snapshot): array
    {
        $prices = [];

        foreach ($snapshot->content->lines as $line) {
            $prices[$line->identity->lineItemId] = $line->unitPriceNet;
        }

        return $prices;
    }

    /** A model answer taking a further 10% off each line's CURRENT price. */
    private static function modelOffersTenPercentOffEveryLine(\MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $snapshot): string
    {
        $rows = [];

        foreach ($snapshot->content->lines as $line) {
            $rows[] = [
                'line_item_id' => $line->identity->lineItemId,
                'unit_price_net' => round($line->unitPriceNet * 0.9, 2),
            ];
        }

        return (string) json_encode([
            'action' => 'offer',
            'line_prices' => $rows,
            'message' => 'A further 10% off each line.',
        ]);
    }
}
