<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

/**
 * The merchant's own LLM credentials. Theirs, not ours: per-tenant model cost
 * stops being the plugin's problem, and the base URL is what lets them point
 * at Azure, their own gateway or a self-hosted model rather than the default.
 */
final readonly class ModelAccess
{
    public function __construct(
        #[\SensitiveParameter]
        public string $apiKey,
        public string $baseUrl,
    ) {}
}
