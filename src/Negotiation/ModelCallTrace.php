<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\Response\ChatEnvelope;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as TransportException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * One model call as a `model_call` trace event: `meta` is the call's figures
 * (TraceKind::ModelCall's allowlist), `content` is what was sent and what came
 * back. Built only after the call is over, so it never has to be amended.
 *
 * `request` is the exact body that was POSTed. The API key is not in it: it
 * travels as `auth_bearer`, a header, and nothing here reads headers.
 */
final readonly class ModelCallTrace
{
    /**
     * @param array<string, mixed> $request
     * @param list<array{httpStatus: int, transportError: bool}> $retries
     */
    public function __construct(
        private ModelCallPurpose $purpose,
        private ModelAccess $access,
        private array $request,
        public int $latencyMs,
        private array $retries,
    ) {}

    /**
     * @param array<array-key, mixed> $decoded
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function answered(int $httpStatus, array $decoded, ?ModelUnavailable $unusable): array
    {
        $meta = [
            ...$this->common($unusable === null ? 'ok' : 'unusable_answer', $httpStatus, $unusable),
            'servedModel' => ChatEnvelope::servedModel($decoded),
            'promptTokens' => ChatEnvelope::usage($decoded, 'prompt_tokens'),
            'completionTokens' => ChatEnvelope::usage($decoded, 'completion_tokens'),
            'cachedTokens' => ChatEnvelope::usage($decoded, 'prompt_tokens_details', 'cached_tokens'),
            'reasoningTokens' => ChatEnvelope::usage($decoded, 'completion_tokens_details', 'reasoning_tokens'),
            'finishReason' => ChatEnvelope::finishReason($decoded),
        ];
        $content = ['request' => $this->request, 'response' => $decoded];

        if ($unusable !== null) {
            $content['error'] = self::error($unusable, null);
        }

        return [$meta, $content];
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    public function failed(ModelUnavailable $error): array
    {
        $cause = $error->getPrevious();
        // getInfo(), not getStatusCode(): the contract lets the latter throw,
        // and a trace must not throw into the pass it describes.
        $httpStatus = $cause instanceof HttpExceptionInterface ? $cause->getResponse()->getInfo('http_code') : null;

        return [
            $this->common('failed', \is_int($httpStatus) ? $httpStatus : null, $error),
            ['request' => $this->request, 'error' => self::error($error, $cause)],
        ];
    }

    /**
     * A scheme-less baseUrl (a merchant typo) makes parse_url() read the
     * whole string as a path, so retry with an assumed scheme to recover the
     * host anyway. Never fall back to the raw string: it can carry a key in
     * its query, and this value lands in a merchant-readable audit column.
     */
    public static function host(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: parse_url('http://' . $baseUrl, PHP_URL_HOST);

        return \is_string($host) ? $host : 'unparsable-host';
    }

    /** @return array<string, mixed> */
    private function common(string $status, ?int $httpStatus, ?ModelUnavailable $error): array
    {
        return [
            'purpose' => $this->purpose->value,
            'requestedModel' => $this->access->model,
            'host' => self::host($this->access->baseUrl),
            'status' => $status,
            'httpStatus' => $httpStatus,
            'latencyMs' => $this->latencyMs,
            'retries' => $this->retries,
            'errorClass' => $error === null ? null : (($error->getPrevious() ?? $error))::class,
        ];
    }

    /**
     * The provider's error body is read with `getContent(false)`, which does
     * not throw on a 4xx/5xx -- but can on a body that never finished
     * arriving, and a trace must not throw into the pass it describes.
     *
     * @return array{message: string, cause: ?string, body: ?string}
     */
    private static function error(ModelUnavailable $error, ?\Throwable $cause): array
    {
        $body = null;

        if ($cause instanceof HttpExceptionInterface) {
            try {
                $body = $cause->getResponse()->getContent(false);
            } catch (TransportException) {
                $body = null;
            }
        }

        return ['message' => $error->getMessage(), 'cause' => $error->getPrevious()?->getMessage(), 'body' => $body];
    }
}
