<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Raw config values to validated settings. Pure — no Shopware — so the whole
 * of the mapping and every refusal is unit-testable without a kernel.
 *
 * Returns null for a disabled channel and throws for a misconfigured one, so
 * a QuoteAgentSettings instance always means "enabled and valid".
 */
final readonly class QuoteAgentSettingsFactory
{
    public function __construct(
        private ValidatorInterface $validator,
    ) {}

    /**
     * @param array<string, mixed> $raw
     *
     * @throws InvalidQuoteAgentConfiguration
     */
    public function fromValues(array $raw): ?QuoteAgentSettings
    {
        // Read first, validate never: a paused agent is silent about
        // everything, including its own bad configuration.
        if (RawConfigValue::bool($raw, 'enabled') !== true) {
            return null;
        }

        $problems = [];
        $policy = null;

        try {
            $policy = NegotiationPolicy::fromArray(NegotiationPolicyArray::build($raw));
        } catch (\TypeError|\ValueError $e) {
            $problems[] = $e->getMessage();
        }

        if ($policy !== null) {
            foreach ($this->validator->validate($policy) as $violation) {
                $problems[] = $violation->getPropertyPath() . ': ' . (string) $violation->getMessage();
            }
        }

        $apiKey = RawConfigValue::stringOrEmpty($raw, 'llmApiKey');

        array_push($problems, ...RawConfigValue::credentialProblems($raw, $apiKey));

        if ($policy === null || $problems !== []) {
            throw new InvalidQuoteAgentConfiguration(array_values($problems));
        }

        return new QuoteAgentSettings(
            policy: $policy,
            llm: RawConfigValue::llm($raw, $apiKey),
            strategyPrompt: RawConfigValue::string($raw, 'negotiationStrategy'),
            notifyBuyerOnEscalation: RawConfigValue::bool($raw, 'notifyBuyerOnEscalation') === true,
        );
    }
}
