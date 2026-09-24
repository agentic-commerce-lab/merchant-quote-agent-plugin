<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/**
 * What the merchant changed in the review card before previewing or sending.
 * Mapped by valinor; the ranges are checked here.
 *
 * No policy ceiling: the caps bind the agent, not the merchant. The card
 * shows a hint above the configured maximum and nothing more.
 */
final readonly class DraftEdits
{
    public ?float $discountPercent;

    /** @var array<string, float> lineItemId => net unit price */
    public array $linePrices;

    public ?\DateTimeImmutable $expiresAt;

    /**
     * @param array<non-empty-string, float> $linePrices
     * @param ?non-empty-string $expiresAt a calendar day, YYYY-MM-DD; valid to its end
     *
     * @throws InvalidReviewRequest
     */
    public function __construct(?float $discountPercent = null, array $linePrices = [], ?string $expiresAt = null)
    {
        if ($discountPercent !== null && ($discountPercent < 0.0 || $discountPercent > 100.0)) {
            throw InvalidReviewRequest::because('The discount must be between 0 and 100 percent.');
        }

        if (array_filter($linePrices, static fn(float $price): bool => $price < 0.0) !== []) {
            throw InvalidReviewRequest::because('A unit price cannot be negative.');
        }

        $this->discountPercent = $discountPercent;
        $this->linePrices = $linePrices;
        $this->expiresAt = $expiresAt === null ? null : self::day($expiresAt);
    }

    public function isEmpty(): bool
    {
        return $this->discountPercent === null && $this->linePrices === [] && $this->expiresAt === null;
    }

    /** @throws InvalidReviewRequest */
    private static function day(string $value): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw InvalidReviewRequest::because('The validity date must be a calendar day, YYYY-MM-DD.');
        }

        $end = $day->setTime(23, 59, 59);

        if ($end < new \DateTimeImmutable()) {
            throw InvalidReviewRequest::because('The validity date is in the past.');
        }

        return $end;
    }
}
