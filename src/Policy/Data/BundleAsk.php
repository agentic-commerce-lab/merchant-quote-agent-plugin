<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class BundleAsk
{
    public function __construct(
        public ?bool $requested = null,
    ) {}
}
