<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Servicing\QuoteEscalator;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
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
            ServicingSettingsFixture::context(),
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
            ServicingSettingsFixture::context(),
        );

        self::assertNull($result);
        self::assertSame([], $gateway->calls, 'A paused agent must not touch the quote.');
    }

    public function testAMisconfiguredChannelWithBuyerNotificationOffEscalatesSilentlyAndReturnsNull(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $result = ServicingSettingsFixture::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        })->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context());

        self::assertNull($result);
        self::assertSame(
            ['updateQuote'],
            $gateway->calls,
            'A merchant who turned the buyer notice off gets no comment.',
        );
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
        self::assertEmpty($gateway->comments);
    }

    /**
     * #140. In services.php the preflight's settings source and the
     * escalator's collaborator are both the same QuoteAgentSettingsReader, and
     * this path is reached BECAUSE forSalesChannel() threw. The old version of
     * this test gave the escalator a second, non-throwing settings source and
     * so asserted a wiring that cannot exist. Two collaborators, two
     * questions, and the second one answerable while the first throws.
     */
    public function testAMisconfiguredChannelWithBuyerNotificationOnCommentsAndMarksTheQuote(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $escalator = new QuoteEscalator(buyerNotification: new FakeBuyerNotification());

        $result = ServicingSettingsFixture::preflight(static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
        }, $escalator)->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context());

        self::assertNull($result);
        self::assertSame(['addComment', 'updateQuote'], $gateway->calls);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
        );
        self::assertStringNotContainsString('API key', implode("\n", $gateway->comments));
    }

    public function testAMisconfiguredChannelRecordsTheEscalationItJustPerformed(): void
    {
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $writer = new FakeDecisionWriter();

        ServicingSettingsFixture::preflight(
            static function (): ?QuoteAgentSettings {
                throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
            },
            null,
            $writer,
        )->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context());

        self::assertCount(1, $writer->drafts);
        self::assertSame('escalated', $writer->drafts[0]->outcome);
        self::assertSame('not_configured', $writer->drafts[0]->escalationReason);
        self::assertSame(['No LLM API key is set.'], $writer->drafts[0]->violations);
        self::assertSame('comment_written', $writer->drafts[0]->triggerReason);
    }

    public function testASilentRefusalRecordsNothing(): void
    {
        // The kill switch and a terminal state are not agent actions. A row
        // per buyer comment saying the agent is paused is the noise that makes
        // an audit trail unreadable.
        $writer = new FakeDecisionWriter();
        $preflight = ServicingSettingsFixture::preflight(static fn(): ?QuoteAgentSettings => null, null, $writer);

        $preflight->check(
            new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]),
            QuoteSnapshotFixture::snapshot(),
            ServicingSettingsFixture::context(),
        );
        $preflight->check(
            new FakeQuoteGateway([QuoteSnapshotFixture::snapshot('accepted')]),
            QuoteSnapshotFixture::snapshot('accepted'),
            ServicingSettingsFixture::context(),
        );

        self::assertSame([], $writer->drafts);
    }

    public function testAnAuditWriteFailureDoesNotStopTheEscalation(): void
    {
        // Same trade as NegotiationPipeline::record(): a throw here would roll
        // the message back into Messenger's retry over a row nobody reads
        // before the quote is escalated. A missing record beats a redelivery.
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);
        $writer = new FakeDecisionWriter();
        $writer->throws = new \RuntimeException('the audit table is gone');

        $result = ServicingSettingsFixture::preflight(
            static function (): ?QuoteAgentSettings {
                throw new InvalidQuoteAgentConfiguration(['No LLM API key is set.']);
            },
            null,
            $writer,
        )->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context());

        self::assertNull($result);
        self::assertSame(
            [QuoteEscalator::MARKER_KEY => 'not_configured'],
            ServicingHandlerFixture::lastCustomFieldWrite($gateway),
            'The escalation must stand even when its record does not.',
        );
    }
}
