<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\Response\ChatEnvelope;
use MerchantQuoteAgentPlugin\Negotiation\Response\ModelAnswerSerializer;
use MerchantQuoteAgentPlugin\Negotiation\Response\ResponseFormatFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
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
 * Exactly ONE retry. A servicing pass makes up to three calls and the quote
 * lock's TTL is 300 seconds; at a 30s timeout plus one 2s backoff, three calls
 * worst-case is already about three minutes. A second retry would risk the
 * lock expiring mid-pass, which is a far worse failure than escalating.
 */
final readonly class ModelPlatform
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_DELAY_MILLISECONDS = 2_000;

    private const MAX_RETRIES = 1;

    /**
     * GenericRetryStrategy's own defaults restrict transport failures and most
     * 5xx to idempotent methods, which would leave a POST — every call we make
     * — retried on almost nothing. Listing the codes bare lifts that
     * restriction, and it is safe precisely here: a chat completion has no
     * side effect on the provider, so a duplicate request costs a second call
     * and nothing else. `0` is the strategy's marker for a transport failure.
     */
    private const RETRY_ON = [0, 423, 425, 429, 500, 502, 503, 504, 507, 510];

    private HttpClientInterface $http;

    public function __construct(
        HttpClientInterface $http,
        LoggerInterface $logger,
        private DecisionRecorder $recorder,
    ) {
        // The retry is the transport's job: a flat 2s backoff (multiplier 1.0,
        // no jitter) keeps the worst case the docblock above computes, and
        // GenericRetryStrategy retries only what is actually transient. A
        // malformed envelope is not, and retrying it would fail the same way.
        //
        // One consequence worth knowing when reading the audit trail: a retried
        // call's modelLatencyMs includes the failed attempt and the backoff.
        $this->http = new RetryableHttpClient(
            $http->withOptions(['timeout' => self::TIMEOUT_SECONDS]),
            new GenericRetryStrategy(
                self::RETRY_ON,
                delayMs: self::RETRY_DELAY_MILLISECONDS,
                multiplier: 1.0,
                jitter: 0.0,
            ),
            self::MAX_RETRIES,
            $logger,
        );
    }

    /** @throws ModelUnavailable */
    public function text(ModelAccess $access, string $system, string $user): string
    {
        return $this->send($access, $system, $user, options: []);
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
        $answer = $this->send($access, $system, $user, ['response_format' => $format]);

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
     * @param array<string, mixed> $options extra top-level request body fields
     *
     * @throws ModelUnavailable
     */
    private function send(ModelAccess $access, string $system, string $user, array $options): string
    {
        // ModelAccess is built from merchant config where the model name may be
        // left blank (RawConfigValue::llm reports that as a credential problem
        // but still constructs the object). Escalating here beats POSTing an
        // empty `model` and letting the provider decide what that means.
        if ($access->model === '') {
            throw new ModelUnavailable('No model name is configured for this sales channel.');
        }

        $startedAt = microtime(true);
        $decoded = $this->post($access, [
            ...$options,
            'model' => $access->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        // Only the attempt that reaches here gets recorded: a failed call throws
        // out of post() above, so it never has a body to read tokens from. Not
        // every OpenAI-compatible provider sends `usage`, so a missing count is
        // null rather than an error — a model call that happened is worth
        // recording even when its cost is unknown.
        $this->recorder->recordModelCall(
            $access->model,
            self::hostOnly($access->baseUrl),
            ChatEnvelope::usage($decoded, 'prompt_tokens'),
            ChatEnvelope::usage($decoded, 'completion_tokens'),
            (int) round((microtime(true) - $startedAt) * 1000),
        );

        return ChatEnvelope::content($decoded);
    }

    /**
     * ResponseInterface::toArray() is typed on array-key, but a JSON object
     * body decodes to string keys and a non-object body throws out of it.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<array-key, mixed>
     *
     * @throws ModelUnavailable
     */
    private function post(ModelAccess $access, array $payload): array
    {
        try {
            // toArray() is what turns a non-2xx into an exception AND decodes
            // the body, so both failure modes land in the one catch below.
            return $this->http->request('POST', rtrim($access->baseUrl, '/') . '/chat/completions', [
                'auth_bearer' => $access->apiKey,
                'json' => $payload,
            ])->toArray();
        } catch (TransportException $e) {
            // The transport contract covers a connection that never landed, a
            // 4xx/5xx the retry could not rescue, and a body that would not
            // decode. Every one of them means the same thing here: escalate.
            throw new ModelUnavailable('The model could not be reached.', previous: $e);
        }
    }

    /**
     * A scheme-less baseUrl (a merchant typo) makes parse_url() read the
     * whole string as a path, so retry with an assumed scheme to recover the
     * host anyway. Never fall back to the raw string: it can carry a key in
     * its query, and this value lands in a merchant-readable audit column.
     */
    private static function hostOnly(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: parse_url('http://' . $baseUrl, PHP_URL_HOST);

        return \is_string($host) ? $host : 'unparsable-host';
    }
}
