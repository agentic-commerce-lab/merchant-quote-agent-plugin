<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * A deterministic, free stand-in for a buyer: pure arithmetic over two
 * snapshots, no LLM. Exists as the regression gate — a scenario can be
 * asserted to still reach the band it used to reach with no provider in the
 * loop.
 *
 * `$before` must be the scenario's *opening* snapshot on every round, never
 * the previous round's reduced one: the engine anchors the same way in
 * `QuoteBaselineLines::anchor()`, and measuring against the reduced price
 * would drift the buyer's target apart from what the agent is checking.
 */
final readonly class ScriptedBuyer implements SyntheticBuyer
{
    public function __construct(
        private float $targetDiscountPercent,
        private float $concessionRatio,
        private int $patience,
    ) {}

    public function respond(QuoteSnapshot $before, QuoteSnapshot $after, string $agentReply, int $round): BuyerMove
    {
        $openingNet = $before->totals->totalNet;
        $realizedDiscountPercent = (($openingNet - $after->totals->totalNet) / $openingNet) * 100;

        if ($realizedDiscountPercent >= $this->targetDiscountPercent) {
            return BuyerMove::accept();
        }

        if ($round > $this->patience) {
            return BuyerMove::walk();
        }

        $askPercent =
            $realizedDiscountPercent
            + (($this->targetDiscountPercent - $realizedDiscountPercent) * $this->concessionRatio);

        return BuyerMove::counter(\sprintf('That still leaves us short. Can you get to %.1f%% off?', $askPercent));
    }
}
