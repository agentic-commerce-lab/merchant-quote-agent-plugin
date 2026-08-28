<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Servicing\ServicingPreflight;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ServicingPreflightTest extends TestCase
{
    public function testAnEnabledValidChannelReturnsItsSettingsAndWritesNothing(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settings = self::settings();

        $result = self::preflight(static fn(): ?QuoteAgentSettings => $settings)
            ->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertSame($settings, $result);
        self::assertSame([], $gateway->calls);
    }

    public function testADisabledChannelReturnsNullSilently(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $result = self::preflight(static fn(): ?QuoteAgentSettings => null)
            ->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertNull($result);
        self::assertSame([], $gateway->calls, 'A paused agent must not touch the quote.');
    }

    public function testAMisconfiguredChannelEscalatesTheQuoteAndReturnsNull(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $result = self::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        })->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertNull($result);
        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }

    private static function settings(): QuoteAgentSettings
    {
        return new QuoteAgentSettings(
            new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 5.0)),
            rulesOnly: false,
            llm: new ModelAccess('sk-test', 'https://api.openai.com/v1'),
            strategyPrompt: null,
        );
    }

    /** @param \Closure(): ?QuoteAgentSettings $outcome */
    private static function preflight(\Closure $outcome): ServicingPreflight
    {
        $source = new class($outcome) implements QuoteAgentSettingsSource {
            /** @param \Closure(): ?QuoteAgentSettings $outcome */
            public function __construct(
                private readonly \Closure $outcome,
            ) {}

            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return ($this->outcome)();
            }
        };

        return new ServicingPreflight($source, new QuoteEscalator(), new NullLogger());
    }
}
