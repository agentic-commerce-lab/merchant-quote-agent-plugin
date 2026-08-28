<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use PHPUnit\Framework\TestCase;

final class QuoteEscalatorTest extends TestCase
{
    public function testItWritesOneCommentAndMarksTheQuote(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
        );

        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => QuoteEscalationReason::NotConfigured->value],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
    }

    public function testTheCommentLeaksNoDiagnosticToTheBuyer(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        (new QuoteEscalator())->escalate(
            $gateway,
            QuoteSnapshotFixture::snapshot(),
            QuoteEscalationReason::NotConfigured,
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

        (new QuoteEscalator())->escalate($gateway, $marked, QuoteEscalationReason::NotConfigured);

        self::assertContains('addComment', $gateway->calls);
    }
}
