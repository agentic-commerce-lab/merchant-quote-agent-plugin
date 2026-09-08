<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;

/**
 * The OpenAI chat-completions envelope: the wrapper around the model's answer,
 * not the answer itself.
 *
 * Separate from ModelPlatform because it is the one provider-shaped thing left
 * in the model path — every OpenAI-compatible endpoint returns `choices` and
 * most return `usage` — while the answer inside it is described by a JSON
 * schema and mapped by the serializer. Splitting them also keeps ModelPlatform
 * inside the class-scoped complexity gate.
 */
final class ChatEnvelope
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @throws ModelUnavailable
     */
    public static function content(array $decoded): string
    {
        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if (!\is_string($content) || $content === '') {
            throw new ModelUnavailable('The model returned no usable message content.');
        }

        return $content;
    }

    /**
     * The `usage` block is OpenAI's shape and not every provider sends it, so a
     * missing count is null rather than an error — a model call that happened
     * is worth recording even when its cost is unknown.
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function usage(array $decoded, string $key): ?int
    {
        $value = $decoded['usage'][$key] ?? null;

        return \is_int($value) ? $value : null;
    }
}
