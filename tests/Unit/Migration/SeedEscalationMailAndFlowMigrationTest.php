<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Migration;

use MerchantQuoteAgentPlugin\Migration\Migration1789500001SeedEscalationMailAndFlow;
use PHPUnit\Framework\TestCase;

/**
 * No database in this suite (`composer run test` runs no kernel), so this
 * pins the migration's source against regressions a DB-backed test would
 * normally catch: every insert idempotent, the flow wired to the real event
 * name and action, and shipped disabled — see the class docblock for why.
 */
final class SeedEscalationMailAndFlowMigrationTest extends TestCase
{
    private static function source(): string
    {
        $file = (new \ReflectionClass(Migration1789500001SeedEscalationMailAndFlow::class))->getFileName();
        self::assertIsString($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    public function testTheTimestampIsAfterTheLatestMigration(): void
    {
        self::assertSame(1789500001, (new Migration1789500001SeedEscalationMailAndFlow())->getCreationTimestamp());
    }

    public function testEveryInsertIsIdempotent(): void
    {
        $source = self::source();

        $totalInserts = preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO/', $source);
        $ignoredInserts = substr_count(haystack: $source, needle: 'INSERT IGNORE INTO');

        self::assertGreaterThan(0, $totalInserts, 'expected at least one insert');
        self::assertSame(
            $totalInserts,
            $ignoredInserts,
            'every insert in a seeding migration must be idempotent (INSERT IGNORE), not a plain INSERT',
        );
    }

    public function testTheFlowIsWiredToTheRealEscalationEvent(): void
    {
        self::assertStringContainsString('QuoteAgentEscalatedEvent::EVENT_NAME', self::source());
    }

    public function testTheFlowSendsMailThroughCoresRealAction(): void
    {
        self::assertStringContainsString('SendMailAction::ACTION_NAME', self::source());
    }

    /**
     * See the class docblock: the Administration notification already covers
     * "no setup needed"; this flow is disabled until a merchant reviews it.
     */
    public function testTheShippedFlowIsDisabledByDefault(): void
    {
        self::assertStringContainsString(
            'VALUES (:id, :name, :eventName, 1, 0, :createdAt)',
            self::source(),
            'the flow row must insert `active` = 0',
        );
    }

    /** No buyer address exists on an escalation, so recipients cannot be "default" or "custom". */
    public function testTheRecipientIsTheAdminRoleNotAGuessedAddress(): void
    {
        self::assertStringContainsString("'type' => 'admin'", self::source());
    }

    public function testTheFlowSequenceReferencesTheSameTemplateIdItSeeds(): void
    {
        $source = self::source();

        self::assertSame(1, preg_match("/public const MAIL_TEMPLATE_ID = '([0-9a-f]{32})';/", $source, $matches));

        self::assertStringContainsString("'mailTemplateId' => self::MAIL_TEMPLATE_ID", $source);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $matches[1]);
    }
}
