<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class PaymentAsk
{
    public function __construct(
        public ?PaymentTerm $requestedTerm = null,
        #[Assert\PositiveOrZero]
        public ?int $requestedNetDays = null,
        #[Assert\Range(min: 0, max: 100)]
        public ?float $requestedDepositPercent = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        $term = OptionalShape::string($data, 'requestedTerm');

        return new self(
            requestedTerm: $term === null ? null : PaymentTerm::from($term),
            requestedNetDays: OptionalShape::int($data, 'requestedNetDays'),
            requestedDepositPercent: OptionalShape::float($data, 'requestedDepositPercent'),
        );
    }
}
