<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
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

        $response = $this->http->request('POST', rtrim($access->baseUrl, '/') . '/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $access->apiKey],
            'json' => $payload,
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        return self::content((string) $response->getBody());
    }

    /** @throws ModelUnavailable */
    private static function content(string $body): string
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModelUnavailable('The model returned a body that is not JSON.', previous: $e);
        }

        $content = \is_array($decoded) ? $decoded['choices'][0]['message']['content'] ?? null : null;

        if (!\is_string($content) || $content === '') {
            throw new ModelUnavailable('The model response carried no message content.');
        }

        return $content;
    }
}
