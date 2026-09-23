<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\PendingEscalation;
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
            QuoteEscalationReason::NotConfigured->value,
            ServicingHandlerFixture::lastCustomFieldWrite($gateway)[QuoteEscalator::MARKER_KEY] ?? null,
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
            QuoteEscalationReason::NeedsHumanReview->value,
            ServicingHandlerFixture::lastCustomFieldWrite($gateway)[QuoteEscalator::MARKER_KEY] ?? null,
        );
    }

    public function testItDefaultsToSilentWhenNoPreferenceIsWired(): void
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

    public function testItResolvesBuyerNotificationFromTheMerchantsToggle(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $preference = new FakeBuyerNotification(notify: false);
        $escalator = new QuoteEscalator(buyerNotification: $preference);

        $escalator->escalate($gateway, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['updateQuote'], $gateway->calls);

        $gateway2 = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $preference->notify = true;
        $escalator->escalate($gateway2, QuoteSnapshotFixture::snapshot(), QuoteEscalationReason::NeedsHumanReview);
        self::assertSame(['addComment', 'updateQuote'], $gateway2->calls);
    }

    /**
     * #140. A NotConfigured escalation exists BECAUSE the configuration is
     * unusable, so the toggle must be readable without it. The preference is
     * read raw and cannot throw; this pins that the escalator no longer
     * swallows an unreadable configuration into silence.
     */
    public function testAMisconfiguredShopStillTellsTheBuyer(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator(buyerNotification: new FakeBuyerNotification()))->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
        );

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            QuoteEscalationReason::NotConfigured->value,
            ServicingHandlerFixture::lastCustomFieldWrite($gateway)[QuoteEscalator::MARKER_KEY] ?? null,
        );
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

    public function testTheMarkerCarriesWhenTheQuoteWasEscalated(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $before = (new \DateTimeImmutable())->format('U.u');

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
        );

        $at = ServicingHandlerFixture::lastCustomFieldWrite($gateway)[PendingEscalation::ESCALATED_AT_KEY] ?? null;
        self::assertIsString($at);
        self::assertGreaterThanOrEqual($before, $at);
    }

    /**
     * The once-per-reason guard covers one open escalation, not the quote's
     * whole life: once a human has sent an answer, the same reason escalating
     * again is news to the buyer, and the time must move so the new
     * escalation is not read as already answered.
     */
    public function testTheSameReasonEscalatesAfreshOnceAHumanHasAnswered(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $fixture = QuoteSnapshotFixture::snapshot();
        $answered = new QuoteSnapshot(
            identity: $fixture->identity,
            revision: $fixture->revision,
            totals: $fixture->totals,
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: 'change_requested',
                customFields: [
                    QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NotConfigured->value,
                    PendingEscalation::ESCALATED_AT_KEY => '1790154000.000000',
                ],
                lastAdminTransitionAt: new \DateTimeImmutable('@1790157600'),
                lastAdminTransitionTo: 'replied',
            ),
            content: $fixture->content,
        );
        $before = (new \DateTimeImmutable())->format('U.u');

        (new QuoteEscalator())->escalate($gateway, $answered, QuoteEscalationReason::NotConfigured, notifyBuyer: true);

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertGreaterThanOrEqual(
            $before,
            ServicingHandlerFixture::lastCustomFieldWrite($gateway)[PendingEscalation::ESCALATED_AT_KEY] ?? '',
        );
    }
}
