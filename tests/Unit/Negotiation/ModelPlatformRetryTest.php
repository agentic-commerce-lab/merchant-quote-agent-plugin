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
            self::assertSame(2, $spy->calls, 'Retried more than once; three calls must fit inside the 300s lock TTL.');
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
}
