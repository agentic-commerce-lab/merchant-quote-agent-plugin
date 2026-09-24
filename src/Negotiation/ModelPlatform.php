<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\TraceKind;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\Response\ChatEnvelope;
use MerchantQuoteAgentPlugin\Negotiation\Response\ModelAnswerSerializer;
use MerchantQuoteAgentPlugin\Negotiation\Response\ResponseFormatFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One `POST /chat/completions`, used by all three prompts. No agent loop, no
 * tool calling — the model answers once and the rules decide what that answer
 * is allowed to do.
 *
 * The credentials and the endpoint are the merchant's (see ModelAccess), so a
 * shop may point this at Azure, their own gateway or a self-hosted model. That
 * is why the request is built here rather than taken from a provider-specific
 * client.
 *
 * WHAT THIS DELIBERATELY DOES NOT USE. Symfony AI's Generic platform bridge
 * makes exactly this POST, and this class was built on it first. The bridge
 * ships a Flex recipe that writes `config/packages/ai_generic_platform.yaml`
 * declaring an `ai` extension only symfony/ai-bundle provides — which no shop
 * installing this plugin has. The result is fatal and immediate:
 *
 *     There is no extension able to load the configuration for "ai"
 *     ... Looked for namespace "ai", found "framework", "shopware", ...
 *
 * The shop does not boot until someone deletes a file they have never heard
 * of, and `composer require` is what creates it. Measured on Shopware 6.7.13.1.
 * A recipe is the root project's to accept or refuse, so a dependency cannot
 * suppress it: not depending on the bridge is the only fix available to us.
 *
 * Symfony AI is still used for the part that earned its keep — generating the
 * JSON schema from the DTOs, in ResponseFormatFactory. symfony/ai-platform
 * ships no recipe of its own (verified against a shop's symfony.lock), so that
 * dependency is safe to keep.
 *
 * Exactly ONE retry, sharing a 30s total transport duration with the first
 * attempt. At most 2s backoff is admitted. Five logical calls (extraction,
 * three negotiate calls, reply) therefore budget at most 160s for the model,
 * leaving time for DAL reads and writes inside the 300s quote lock TTL.
 *
 * Every logical call leaves one `model_call` trace event (ModelCallTrace),
 * including a call that failed and one whose answer did not map — the two a
 * merchant most needs to read afterwards.
 */
final readonly class ModelPlatform
{
    private const TIMEOUT_SECONDS = 30;

    private const MAX_RETRIES = 1;

    private HttpClientInterface $http;

    private ModelRetryStrategy $retries;

    public function __construct(
        HttpClientInterface $http,
        LoggerInterface $logger,
        private DecisionRecorder $recorder,
    ) {
        // Symfony subtracts elapsed request time from max_duration on retry.
        // An idle timeout alone does not bound a slowly arriving response.
        // ModelRetryStrategy also refuses long provider Retry-After values,
        // which RetryableHttpClient otherwise honors without a delay cap.
        //
        // One consequence worth knowing when reading the audit trail: a retried
        // call's modelLatencyMs includes the failed attempt and the backoff.
        $this->retries = new ModelRetryStrategy();
        $this->http = new RetryableHttpClient(
            $http->withOptions(['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::TIMEOUT_SECONDS]),
            $this->retries,
            self::MAX_RETRIES,
            $logger,
        );
    }

    /** @throws ModelUnavailable */
    public function text(ModelAccess $access, string $system, string $user): string
    {
        return $this->call(
            ModelCallPurpose::Reply,
            $access,
            self::payload($access, $system, $user, []),
            static fn(string $answer): string => $answer,
        );
    }

    /**
     * The model answers under a JSON schema generated from `$type`, and the
     * answer is mapped straight onto it. Anything unusable throws: there is no
     * partial read and no default-and-carry-on, because an answer the model did
     * not actually produce would put words in the buyer's mouth and the
     * deciders would act on them.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws ModelUnavailable
     */
    public function object(ModelAccess $access, string $system, string $user, string $type): object
    {
        $format = (new ResponseFormatFactory())->create($type);

        return $this->call(
            ModelCallPurpose::answering($type),
            $access,
            self::payload($access, $system, $user, ['response_format' => $format]),
            static fn(string $answer): object => self::mapped($answer, $type),
        );
    }

    /**
     * One logical call, traced whatever happens to it. `$read` turns the
     * message content into the caller's answer and may throw
     * ModelUnavailable: that is the `unusable_answer` case, where the call
     * worked and its answer is what went wrong.
     *
     * @template R
     *
     * @param array<string, mixed> $payload
     * @param \Closure(string): R $read
     *
     * @return R
     *
     * @throws ModelUnavailable
     */
    private function call(ModelCallPurpose $purpose, ModelAccess $access, array $payload, \Closure $read): mixed
    {
        // ModelAccess is built from merchant config where the model name may be
        // left blank (RawConfigValue::llm reports that as a credential problem
        // but still constructs the object). Escalating here beats POSTing an
        // empty `model` and letting the provider decide what that means. No
        // trace: no call happened.
        if ($access->model === '') {
            throw new ModelUnavailable('No model name is configured for this sales channel.');
        }

        $this->retries->reset();
        $startedAt = microtime(true);

        try {
            [$httpStatus, $decoded] = $this->post($access, $payload);
        } catch (ModelUnavailable $e) {
            $this->recorder->trace(
                TraceKind::ModelCall,
                ...$this->traceOf($purpose, $access, $payload, $startedAt)->failed($e),
            );

            throw $e;
        }

        $trace = $this->traceOf($purpose, $access, $payload, $startedAt);

        // The running totals on the decision row stay: the dashboard and
        // bench-score.mjs read them. Not every OpenAI-compatible provider
        // sends `usage`, so a missing count is null rather than an error.
        $this->recorder->recordModelCall(
            $access->model,
            ModelCallTrace::host($access->baseUrl),
            ChatEnvelope::usage($decoded, 'prompt_tokens'),
            ChatEnvelope::usage($decoded, 'completion_tokens'),
            $trace->latencyMs,
        );

        try {
            $answer = $read(ChatEnvelope::content($decoded));
        } catch (ModelUnavailable $e) {
            $this->recorder->trace(TraceKind::ModelCall, ...$trace->answered($httpStatus, $decoded, $e));

            throw $e;
        }

        $this->recorder->trace(TraceKind::ModelCall, ...$trace->answered($httpStatus, $decoded, null));

        return $answer;
    }

    /** @param array<string, mixed> $payload */
    private function traceOf(
        ModelCallPurpose $purpose,
        ModelAccess $access,
        array $payload,
        float $startedAt,
    ): ModelCallTrace {
        return new ModelCallTrace(
            $purpose,
            $access,
            $payload,
            (int) round((microtime(true) - $startedAt) * 1000),
            $this->retries->attempts(),
        );
    }

    /**
     * @param array<string, mixed> $options extra top-level request body fields
     *
     * @return array<string, mixed>
     */
    private static function payload(ModelAccess $access, string $system, string $user, array $options): array
    {
        return [
            ...$options,
            'model' => $access->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws ModelUnavailable
     */
    private static function mapped(string $answer, string $type): object
    {
        try {
            $mapped = (new ModelAnswerSerializer())->deserialize($answer, $type, 'json');
        } catch (SerializerException|\JsonException $e) {
            throw new ModelUnavailable('The model did not answer in the requested shape.', previous: $e);
        }

        if (!$mapped instanceof $type) {
            throw new ModelUnavailable('The model did not answer in the requested shape.');
        }

        return $mapped;
    }

    /**
     * ResponseInterface::toArray() is typed on array-key, but a JSON object
     * body decodes to string keys and a non-object body throws out of it.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: array<array-key, mixed>}
     *
     * @throws ModelUnavailable
     */
    private function post(ModelAccess $access, array $payload): array
    {
        try {
            // toArray() is what turns a non-2xx into an exception AND decodes
            // the body, so both failure modes land in the one catch below.
            $response = $this->http->request('POST', rtrim($access->baseUrl, '/') . '/chat/completions', [
                'auth_bearer' => $access->apiKey,
                'json' => $payload,
            ]);

            return [$response->getStatusCode(), $response->toArray()];
        } catch (TransportException $e) {
            // The transport contract covers a connection that never landed, a
            // 4xx/5xx the retry could not rescue, and a body that would not
            // decode. Every one of them means the same thing here: escalate.
            throw new ModelUnavailable('The model could not be reached.', previous: $e);
        }
    }
}
