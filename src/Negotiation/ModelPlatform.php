<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Negotiation\Response\ModelAnswerSerializer;
use MerchantQuoteAgentPlugin\Negotiation\Response\ResponseFormatFactory;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One `POST /chat/completions`, used by all three prompts, through Symfony AI's
 * Generic platform bridge. No agent loop, no tool calling — the model answers
 * once and the rules decide what that answer is allowed to do.
 *
 * The Generic bridge is the one that takes an arbitrary base URL, which is the
 * whole point: the credentials and the endpoint are the merchant's (see
 * ModelAccess), so a shop may point this at Azure, its own gateway or a
 * self-hosted model. A provider-specific bridge would take that away.
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
        // The retry is the transport's job now, not this class's: a flat 2s
        // backoff (multiplier 1.0, no jitter) keeps the worst case the docblock
        // above computes, and GenericRetryStrategy retries only the transport
        // failures and 5xx/429 that are actually transient. A malformed
        // envelope is not, and retrying it would just fail the same way again.
        //
        // One consequence worth knowing when reading the audit trail: a retried
        // call's modelLatencyMs now includes the failed attempt and the backoff,
        // where the hand-rolled retry recorded only the successful attempt.
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
        $content = $this->call($access, $system, $user, options: [])->getContent();

        if (!\is_string($content) || $content === '') {
            throw new ModelUnavailable('The model returned no usable message content.');
        }

        return $content;
    }

    /**
     * The model answers under a JSON schema generated from `$type`, and the
     * answer is mapped straight onto it. Anything unusable throws: there is no
     * partial read and no default-and-carry-on, because an answer the model did
     * not actually produce would put words in the buyer's mouth and the deciders
     * would act on them.
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
        $content = $this->call($access, $system, $user, ['response_format' => $type])->getContent();

        if (!$content instanceof $type) {
            throw new ModelUnavailable('The model did not answer in the requested shape.');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ModelUnavailable
     */
    private function call(ModelAccess $access, string $system, string $user, array $options): ResultInterface
    {
        // ModelAccess is built from merchant config where the model name may be
        // left blank (RawConfigValue::llm reports that as a credential problem
        // but still constructs the object). Escalating here beats POSTing an
        // empty `model` and letting the provider decide what that means.
        if ($access->model === '') {
            throw new ModelUnavailable('No model name is configured for this sales channel.');
        }

        $startedAt = microtime(true);

        try {
            $result = $this
                ->platform($access)
                ->invoke($access->model, new MessageBag(Message::forSystem($system), Message::ofUser($user)), $options)
                ->getResult();
        } catch (PlatformException|TransportException $e) {
            // Everything the bridge can go wrong with rides one of these two:
            // the transport contract for a connection that never landed, the
            // platform contract for a 4xx/5xx, a body that would not decode and
            // an answer that would not map to the requested shape.
            throw new ModelUnavailable('The model could not be reached.', previous: $e);
        }

        $usage = $result->getMetadata()->get('token_usage');
        // Not every OpenAI-compatible provider sends a usage block, so a
        // missing count is null rather than an error — a model call that
        // happened is worth recording even when its cost is unknown.
        $usage = $usage instanceof TokenUsageInterface ? $usage : new TokenUsage();

        $this->recorder->recordModelCall(
            $access->model,
            self::hostOnly($access->baseUrl),
            $usage->getPromptTokens(),
            $usage->getCompletionTokens(),
            (int) round((microtime(true) - $startedAt) * 1000),
        );

        return $result;
    }

    /**
     * Built per call, not once: the merchant's credentials arrive with the
     * settings rather than the container, and PlatformSubscriber carries the
     * requested output type from the invocation to the result — one shared
     * across calls would hand a stale type to the next answer.
     */
    private function platform(ModelAccess $access): Platform
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber(new ResponseFormatFactory(), new ModelAnswerSerializer()));

        return Factory::createPlatform(
            baseUrl: $access->baseUrl,
            apiKey: $access->apiKey,
            httpClient: $this->http,
            eventDispatcher: $dispatcher,
            supportsEmbeddings: false,
            // The bridge would default to OpenAI's `/v1/chat/completions`, but
            // the merchant's base URL already carries whatever prefix their
            // gateway uses, so only the endpoint itself belongs here.
            completionsPath: '/chat/completions',
        );
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
