<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

/**
 * How the window's granted discounts sit against each decision's own cap --
 * a mean and a headline count, never a single decision's number. Split out of
 * DayPicture to keep that class's own constructor under the parameter-count
 * gate; see DayPicture's docblock for why this is the only place a discount
 * number survives past the fixtures.
 */
final readonly class DiscountSpread
{
    private function __construct(
        private int $measured,
        private ?float $meanGrantedPercent,
        private int $atOrAboveCap,
    ) {}

    /** @param list<HarvestedDecision> $decisions */
    public static function of(array $decisions): self
    {
        $granted = [];
        $atOrAboveCap = 0;

        foreach ($decisions as $decision) {
            if ($decision->discountPercentGranted === null) {
                continue;
            }

            $granted[] = $decision->discountPercentGranted;

            if (
                $decision->maxDiscountPercent !== null
                && $decision->discountPercentGranted >= $decision->maxDiscountPercent
            ) {
                ++$atOrAboveCap;
            }
        }

        return new self(
            measured: \count($granted),
            meanGrantedPercent: $granted === [] ? null : array_sum($granted) / \count($granted),
            atOrAboveCap: $atOrAboveCap,
        );
    }

    public function describe(): string
    {
        if ($this->measured === 0) {
            return 'No measurable discount grants in the window.';
        }

        return \sprintf(
            '%d grant(s) measured, mean %.1f%% of the order net, %d at or above the merchant cap.',
            $this->measured,
            $this->meanGrantedPercent ?? 0.0,
            $this->atOrAboveCap,
        );
    }
}
