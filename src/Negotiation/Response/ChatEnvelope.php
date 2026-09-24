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
    /** Only documented chat-completion machine values may leave with always-exported trace metadata. */
    private const FINISH_REASONS = ['stop', 'length', 'tool_calls', 'content_filter', 'function_call'];

    private function __construct() {}

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @throws ModelUnavailable
     */
    public static function content(array $decoded): string
    {
        $content = self::at($decoded, 'choices', 0, 'message', 'content');

        if (!\is_string($content) || $content === '') {
            throw new ModelUnavailable('The model returned no usable message content.');
        }

        return $content;
    }

    /**
     * The `usage` block is OpenAI's shape and not every provider sends it, so a
     * missing count is null rather than an error — a model call that happened
     * is worth recording even when its cost is unknown. A path reaches the
     * nested detail blocks (`prompt_tokens_details.cached_tokens`).
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function usage(array $decoded, string ...$path): ?int
    {
        $value = self::at($decoded, 'usage', ...$path);

        return \is_int($value) ? $value : null;
    }

    /**
     * Unknown provider values stay in the raw response behind the free-text
     * gate. Compatible gateways may send arbitrary strings here.
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function finishReason(array $decoded): ?string
    {
        $reason = self::at($decoded, 'choices', 0, 'finish_reason');

        return \is_string($reason) && \in_array($reason, self::FINISH_REASONS, strict: true) ? $reason : null;
    }

    /**
     * The model that actually answered, which a router or an alias can make
     * different from the one requested.
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function servedModel(array $decoded): ?string
    {
        $model = self::at($decoded, 'model');

        return \is_string($model) ? $model : null;
    }

    /**
     * The leaf a path leads to, or null wherever it breaks off: a missing key
     * and a non-array on the way both mean the provider did not send it.
     *
     * @param array<array-key, mixed> $decoded
     */
    private static function at(array $decoded, int|string ...$path): mixed
    {
        $value = $decoded;

        foreach ($path as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }
}
