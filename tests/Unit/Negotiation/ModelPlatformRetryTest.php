<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Exactly one retry, and it covers a POST. Symfony's own retry defaults would
 * skip both the transport failures and the 500s on a non-idempotent method,
 * which would leave the agent escalating on a blip the old client rode out.
 */
final class ModelPlatformRetryTest extends TestCase
{
    public function testATransientFailureIsRetriedOnce(): void
    {
        [$platform, $spy] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            NegotiationFixture::modelReply('recovered'),
        ]);

        self::assertSame('recovered', $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr'));
        self::assertSame(2, $spy->calls, 'The 503 was not retried.');
    }

    public function testASecondFailureThrowsModelUnavailable(): void
    {
        [$platform, $spy] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 503]),
        ]);

        $this->expectException(ModelUnavailable::class);

        try {
            $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
        } finally {
            self::assertSame(2, $spy->calls, 'Retried more than once within a logical model call.');
        }
    }

    public function testAConnectionFailureIsAlsoTransient(): void
    {
        [$platform] = ScriptedClient::responding([
            new MockResponse('', ['error' => 'timed out']),
            NegotiationFixture::modelReply('recovered'),
        ]);

        self::assertSame('recovered', $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr'));
    }

    public function testRetrySharesTheTotalRequestDurationBudget(): void
    {
        [$platform, $spy] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503, 'total_time' => 20.0]),
            NegotiationFixture::modelReply('recovered'),
        ]);

        self::assertSame('recovered', $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr'));
        self::assertSame(30.0, $spy->requests[0]['options']['max_duration']);
        self::assertEqualsWithDelta(10.0, $spy->requests[1]['options']['max_duration'], 0.1);
    }

    public function testAnExhaustedDurationBudgetDoesNotStartAnotherAttempt(): void
    {
        [$platform, $spy] = ScriptedClient::responding([
            new MockResponse('', ['http_code' => 503, 'total_time' => 31.0]),
        ]);

        $this->expectException(ModelUnavailable::class);

        try {
            $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
        } finally {
            self::assertSame(1, $spy->calls);
        }
    }

    public function testALongRetryAfterEscalatesWithoutWaitingOrRetrying(): void
    {
        foreach (['600', '-10000000000000000', '1e309', gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 600)] as $header) {
            [$platform, $spy] = ScriptedClient::responding([
                new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: ' . $header]]),
            ]);

            try {
                $platform->text(NegotiationFixture::modelAccess(), 'sys', 'usr');
                self::fail('A retry outside the quote lock budget must escalate.');
            } catch (ModelUnavailable) {
                self::assertSame(1, $spy->calls);
            }
        }
    }
}
