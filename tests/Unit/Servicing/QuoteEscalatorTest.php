<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

final class QuoteEscalatorTest extends TestCase
{
    public function testItWritesOneCommentAndMarksTheQuoteWhenNotificationIsEnabled(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
            notifyBuyer: true,
        );

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NotConfigured->value],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }

    public function testItDoesNotWriteCommentWhenBuyerNotificationIsDisabled(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NeedsHumanReview,
            notifyBuyer: false,
        );

        self::assertSame(['updateQuote'], $gateway->calls);
        self::assertEmpty($gateway->comments);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }

    public function testItDefaultsToSilentWhenNoSettingsSourceProvided(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NeedsHumanReview,
        );

        self::assertSame(['updateQuote'], $gateway->calls);
        self::assertEmpty($gateway->comments);
    }

    public function testItResolvesBuyerNotificationFromSettingsSource(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settingsSource = new class implements \MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource {
            public bool $enabled = false;

            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?\MerchantQuoteAgentPlugin\Config\QuoteAgentSettings
            {
                return new \MerchantQuoteAgentPlugin\Config\QuoteAgentSettings(
                    new \MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy(
                        new \MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits(maxDiscountPercent: 5.0),
                    ),
                    new \MerchantQuoteAgentPlugin\Config\ModelAccess('key', 'https://example.com', 'model'),
                    null,
                    notifyBuyerOnEscalation: $this->enabled,
                );
            }
        };

        $escalator = new QuoteEscalator(settingsSource: $settingsSource);

        $settingsSource->enabled = false;
        $escalator->escalate($gateway, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['updateQuote'], $gateway->calls);

        $gateway2 = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settingsSource->enabled = true;
        $escalator->escalate($gateway2, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['addComment', 'updateQuote'], $gateway2->calls);
    }

    public function testItFallsBackToSilentWhenSettingsSourceThrows(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $settingsSource = new class implements \MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource {
            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?\MerchantQuoteAgentPlugin\Config\QuoteAgentSettings
            {
                throw new \MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration(['Invalid config']);
            }
        };

        $escalator = new QuoteEscalator(settingsSource: $settingsSource);
        $escalator->escalate($gateway, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);

        self::assertSame(['updateQuote'], $gateway->calls);
        self::assertEmpty($gateway->comments);
    }

    public function testTheCommentLeaksNoDiagnosticToTheBuyer(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
            notifyBuyer: true,
        );

        // The storefront shows quote comments to the CUSTOMER, unfiltered.
        // escalate() takes no caller text, so $reason is the only thing left
        // that could reach the buyer. It must not.
        self::assertStringNotContainsString(
            QuoteEscalationReason::NotConfigured->value,
            implode("\n", $gateway->comments),
        );
    }

    public function testItSkipsAQuoteAlreadyMarkedWithTheSameReason(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $marked = QuoteSnapshotFixture::snapshot(customFields: [
            QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NotConfigured->value,
        ]);

        (new QuoteEscalator())->escalate($gateway, $marked, QuoteEscalationReason::NotConfigured);

        self::assertSame([], $gateway->calls, 'A misconfigured shop must not add one comment per buyer comment.');
    }

    public function testADifferentReasonStillEscalates(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $marked = QuoteSnapshotFixture::snapshot(customFields: [
            QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NeedsHumanReview->value,
        ]);

        (new QuoteEscalator())->escalate($gateway, $marked, QuoteEscalationReason::NotConfigured, notifyBuyer: true);

        self::assertContains('addComment', $gateway->calls);
    }
}
