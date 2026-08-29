<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use Psr\Log\LoggerInterface;

/**
 * One `POST /chat/completions`, used by all three prompts. No agent loop, no
 * tool calling — the model answers once and the rules decide what that answer
 * is allowed to do.
 *
 * Exactly ONE retry. A servicing pass makes up to three calls and the quote
 * lock's TTL is 300 seconds; at a 30s timeout plus one 2s backoff, three calls
 * worst-case is already about three minutes. A second retry would risk the
 * lock expiring mid-pass, which is a far worse failure than escalating.
 */
final readonly class ChatCompletionClient
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_DELAY_MICROSECONDS = 2_000_000;

    public function __construct(
        private ClientInterface $http,
        private LoggerInterface $logger,
        private DecisionRecorder $recorder,
    ) {}

    /** @throws ModelUnavailable */
    public function complete(ModelAccess $access, string $system, string $user, bool $json): string
    {
        try {
            return $this->send($access, $system, $user, $json);
        } catch (GuzzleException $first) {
            // Only transport failures are retried: a malformed envelope isn't
            // transient, and retrying it would just burn the one retry we
            // have on a call that's going to fail the same way again.
            $this->logger->info('The model call failed; retrying once.', ['exception' => $first]);
            usleep(self::RETRY_DELAY_MICROSECONDS);
        }

        try {
            return $this->send($access, $system, $user, $json);
        } catch (GuzzleException $second) {
            throw new ModelUnavailable('The model could not be reached.', previous: $second);
        }
    }

    /** @throws ModelUnavailable|GuzzleException */
    private function send(ModelAccess $access, string $system, string $user, bool $json): string
    {
        $payload = [
            'model' => $access->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $startedAt = microtime(true);

        $response = $this->http->request('POST', rtrim($access->baseUrl, '/') . '/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $access->apiKey],
            'json' => $payload,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        // Decoded once and shared by usage() and content(), so a malformed
        // body costs one try/catch instead of two — every branch here adds to
        // the class-wide complexity budget.
        try {
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        // Only the attempt that reaches here gets recorded: a failed transport
        // call throws out of $this->http->request() above, so it never has a
        // body to read tokens from. Its wall-clock cost isn't lost though — it
        // still shows up in the pass's own durationMs. So when complete()
        // retries, modelLatencyMs is the successful call's time only, not the
        // total time spent waiting on the model.
        $this->recorder->recordModelCall(
            $access->model,
            parse_url($access->baseUrl, PHP_URL_HOST) ?: $access->baseUrl,
            self::usage($decoded, 'prompt_tokens'),
            self::usage($decoded, 'completion_tokens'),
            (int) round((microtime(true) - $startedAt) * 1000),
        );

        return self::content($decoded);
    }

    /**
     * The `usage` block is OpenAI's shape and not every provider sends it, so
     * a missing count is null rather than an error — a model call that
     * happened is worth recording even when its cost is unknown.
     */
    private static function usage(mixed $decoded, string $key): ?int
    {
        // `??` treats a non-array $decoded the same as a missing key: both
        // fall through to null without a warning, so no is_array() branch is
        // needed here.
        $value = $decoded['usage'][$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /** @throws ModelUnavailable */
    private static function content(mixed $decoded): string
    {
        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if (!\is_string($content) || $content === '') {
            throw new ModelUnavailable('The model returned no usable message content.');
        }

        return $content;
    }
}
