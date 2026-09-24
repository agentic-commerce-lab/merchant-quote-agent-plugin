<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Servicing\ServicingJournal;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeTraceWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ServicingPreflightSkipTraceTest extends TestCase
{
    public function testTerminalStateAndKillSwitchEachLeaveASkip(): void
    {
        $writer = new FakeTraceWriter();
        $journal = new ServicingJournal(new NullLogger(), $writer);
        $preflight = ServicingSettingsFixture::preflight(static fn(): ?QuoteAgentSettings => null, journal: $journal);
        $gateway = new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]);

        $preflight->check($gateway, QuoteSnapshotFixture::snapshot('accepted'), ServicingSettingsFixture::context());
        $preflight->check($gateway, QuoteSnapshotFixture::snapshot(), ServicingSettingsFixture::context());

        self::assertSame(
            ['terminal_state', 'kill_switch'],
            array_column(array_map(static fn($event): array => $event->meta, $writer->events), 'reason'),
        );
        self::assertSame('preflight', $writer->events[0]->meta['source']);
        self::assertNull($writer->events[0]->customerId, 'The fixture has no customer id.');
    }

    public function testFailedRefusalWriteLeavesASkipButSuccessfulRefusalDoesNot(): void
    {
        $writer = new FakeTraceWriter();
        $journal = new ServicingJournal(new NullLogger(), $writer);
        $decisions = new FakeDecisionWriter();
        $decisions->throws = new \RuntimeException('decision table unavailable');
        $config = static function (): ?QuoteAgentSettings {
            throw new InvalidQuoteAgentConfiguration(['No model key.']);
        };

        ServicingSettingsFixture::preflight($config, writer: $decisions, journal: $journal)->check(
            new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]),
            QuoteSnapshotFixture::snapshot(),
            ServicingSettingsFixture::context(),
        );
        self::assertCount(1, $writer->events);
        self::assertSame('refusal_write_failed', $writer->events[0]->meta['reason']);

        ServicingSettingsFixture::preflight($config, journal: $journal)->check(
            new FakeQuoteGateway([QuoteSnapshotFixture::snapshot()]),
            QuoteSnapshotFixture::snapshot(),
            ServicingSettingsFixture::context(),
        );
        self::assertCount(1, $writer->events);
    }
}
