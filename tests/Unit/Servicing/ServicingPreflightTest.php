<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

final class ServicingPreflightTest extends TestCase
{
    public function testAnEnabledValidChannelReturnsItsSettingsAndWritesNothing(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settings = ServicingSettingsFixture::settings();

        $result = ServicingSettingsFixture::preflight(static fn(): ?QuoteAgentSettings => $settings)->check(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
        );

        self::assertSame($settings, $result);
        self::assertSame([], $gateway->calls);
    }

    public function testADisabledChannelReturnsNullSilently(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $result = ServicingSettingsFixture::preflight(static fn(): ?QuoteAgentSettings => null)->check(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
        );

        self::assertNull($result);
        self::assertSame([], $gateway->calls, 'A paused agent must not touch the quote.');
    }

    public function testAMisconfiguredChannelEscalatesTheQuoteQuietlyByDefaultAndReturnsNull(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $result = ServicingSettingsFixture::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        })->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertNull($result);
        self::assertSame(['updateQuote'], $gateway->calls, 'Misconfigured channel escalates quietly by default.');
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
        self::assertEmpty($gateway->comments);
    }

    public function testAMisconfiguredChannelWithBuyerNotificationEnabledWritesCommentAndMarksQuote(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settingsSource = new class implements \MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource {
            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?\MerchantQuoteAgentPlugin\Config\QuoteAgentSettings
            {
                return new \MerchantQuoteAgentPlugin\Config\QuoteAgentSettings(
                    new \MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy(
                        new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits(maxDiscountPercent: 5.0),
                    ),
                    new \MerchantQuoteAgentPlugin\Config\ModelAccess('key', 'https://example.com', 'model'),
                    null,
                    notifyBuyerOnEscalation: true,
                );
            }
        };
        $escalator = new QuoteEscalator(settingsSource: $settingsSource);

        $result = ServicingSettingsFixture::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        }, $escalator)->check($gateway, QuoteSnapshotFixture::snapshot());

        self::assertNull($result);
        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
        self::assertStringNotContainsString('API key', implode("\n", $gateway->comments));
    }
}
