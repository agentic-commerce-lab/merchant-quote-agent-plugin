<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use MerchantQuoteAgentPlugin\Migration\Migration1789500001SeedEscalationMailAndFlow as EscalationMailSeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * #170's leftover, and the row-level counterpart to
 * UninstallDropsEveryTableTest.
 *
 * Migration1789500001SeedEscalationMailAndFlow seeds a mail template and a
 * flow into core's shared `flow` / `mail_template` tables.
 * dropPluginTables() rightly cannot reach them — a DROP TABLE there would
 * take the shop's own mail with it — so a "remove all data" uninstall used to
 * leave a flow behind in Flow Builder, wired to an event name that can no
 * longer fire.
 *
 * Deleting rows in a table the shop owns is the aggressive half of that fix,
 * so most of what follows pins the restraint rather than the deletion: an
 * `updated_at IS NULL` guard on every statement, a dependency check before
 * each of the two rows something else can point at, and an order that makes
 * the second of those checks answerable. Lose any one of them and uninstall
 * starts deleting a merchant's own configuration.
 *
 * No kernel here, for the reasons MerchantQuoteAgentPluginTest sets out; a
 * mocked Connection recording statements is enough to pin the SQL.
 */
final class UninstallDeletesSeededMailAndFlowTest extends TestCase
{
    /**
     * The seeded rows uninstall is responsible for, as [table, seeded id].
     *
     * The seeded `flow_sequence` row is absent on purpose:
     * `fk.flow_sequence.flow_id` is ON DELETE CASCADE, so deleting the flow
     * takes it, and a statement naming it would be one more thing to keep in
     * step for no effect. Same for the two `*_translation` tables and
     * `mail_template_sales_channel`.
     *
     * @var list<array{string, string}>
     */
    private const SEEDED_ROWS = [
        ['flow',               EscalationMailSeed::FLOW_ID],
        ['mail_template',      EscalationMailSeed::MAIL_TEMPLATE_ID],
        ['mail_template_type', EscalationMailSeed::MAIL_TEMPLATE_TYPE_ID],
    ];

    /**
     * Each seeded row gets a DELETE of its own, bound to the id the migration
     * writes and guarded on `updated_at IS NULL`.
     *
     * That guard is what makes deleting in a shared table defensible at all:
     * the seed writes `created_at` only, and any merchant edit — down to
     * flipping the flow on in the list — sets `updated_at` through the DAL.
     * So a merchant who adopted the flow keeps it. Reading the column wrong
     * can only ever leave a row behind, never remove one.
     *
     * @param  string  $table  the core table the row lives in
     * @param  string  $seededId  the hex id the migration seeds, bound as binary
     */
    #[DataProvider('seededRows')]
    public function testEachSeededRowIsDeletedByIdUnlessTheMerchantEditedIt(string $table, string $seededId): void
    {
        $statements = self::uninstallStatements();
        $index = self::indexOfDelete($statements, $table, $seededId);

        self::assertNotNull($index, \sprintf('uninstall() issued no DELETE against `%s`.', $table));
        [$sql, $parameters] = $statements[$index];

        self::assertContains(
            hex2bin($seededId),
            $parameters,
            \sprintf('The DELETE on `%s` must bind the id the migration seeds, as binary.', $table),
        );
        self::assertStringContainsString(
            '`updated_at` IS NULL',
            $sql,
            \sprintf('The DELETE on `%s` must spare a row the merchant has edited.', $table),
        );
        self::assertStringNotContainsString(
            'DROP',
            $sql,
            \sprintf('`%s` is a shared core table — delete the seeded row, never the table.', $table),
        );
    }

    /**
     * `mail_template.mail_template_type_id` is ON DELETE SET NULL, and a
     * merchant can build their own flow on our template, so both of these
     * deletes can reach rows that are not ours. Neither may fire while
     * something still depends on the row.
     */
    public function testTheSharedRowsAreSparedWhileAnythingStillDependsOnThem(): void
    {
        $statements = self::uninstallStatements();

        [$templateSql, $templateParameters] = $statements[(int) self::indexOfDelete(
            $statements,
            'mail_template',
            EscalationMailSeed::MAIL_TEMPLATE_ID,
        )];
        self::assertStringContainsString(
            'flow_sequence',
            $templateSql,
            'The mail template must survive while any flow_sequence still sends it.',
        );
        self::assertContains(
            '%' . EscalationMailSeed::MAIL_TEMPLATE_ID . '%',
            $templateParameters,
            'That check has to look for the template id inside flow_sequence.config.',
        );

        [$typeSql] = $statements[(int) self::indexOfDelete(
            $statements,
            'mail_template_type',
            EscalationMailSeed::MAIL_TEMPLATE_TYPE_ID,
        )];
        self::assertStringContainsString(
            'FROM `mail_template` WHERE `mail_template_type_id`',
            $typeSql,
            'The type must survive while any mail_template still points at it, or the FK nulls their type.',
        );
    }

    /**
     * Order is load-bearing, not cosmetic: the flow has to be gone before the
     * template is considered, or the flow's own `flow_sequence` row trips the
     * dependency guard above and the template is kept forever. The type comes
     * last for the same reason.
     */
    public function testTheFlowIsDeletedBeforeTheTemplateItSends(): void
    {
        $statements = self::uninstallStatements();

        $flow = self::indexOfDelete($statements, 'flow', EscalationMailSeed::FLOW_ID);
        $template = self::indexOfDelete($statements, 'mail_template', EscalationMailSeed::MAIL_TEMPLATE_ID);
        $type = self::indexOfDelete($statements, 'mail_template_type', EscalationMailSeed::MAIL_TEMPLATE_TYPE_ID);

        self::assertNotNull($flow);
        self::assertNotNull($template);
        self::assertNotNull($type);
        self::assertLessThan($template, $flow, 'The flow must be deleted before the template it sends.');
        self::assertLessThan($type, $template, 'The template must be deleted before its type.');
    }

    /** A merchant who keeps their data keeps these rows too — the same branch that spares the tables. */
    public function testNothingIsDeletedWhenUserDataIsKept(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        self::plugin($connection)->uninstall(self::context(keepUserData: true));
    }

    /**
     * The delete side names the same rows the seed side writes. A hex literal
     * copied into the plugin instead would drift the first time either moves,
     * and the failure is invisible — a row silently left behind on a shop.
     */
    public function testTheDeletedIdsComeFromTheMigrationRatherThanACopy(): void
    {
        $file = (new \ReflectionClass(MerchantQuoteAgentPlugin::class))->getFileName();
        self::assertIsString($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        self::assertSame(
            0,
            preg_match("/'[0-9a-f]{32}'/", $source),
            "MerchantQuoteAgentPlugin must reference the migration's id constants, not copy the hex.",
        );
    }

    /** @return list<array{string, string}> */
    public static function seededRows(): array
    {
        return self::SEEDED_ROWS;
    }

    /**
     * Matched on the bound id as well as the table name, because
     * `mail_template` is a substring of `mail_template_type` and of the guard
     * subqueries.
     *
     * @param  list<array{string, array<string, mixed>}>  $statements
     */
    private static function indexOfDelete(array $statements, string $table, string $seededId): ?int
    {
        $binary = hex2bin($seededId);

        foreach ($statements as $index => [$sql, $parameters]) {
            if (
                str_contains($sql, 'DELETE FROM `' . $table . '`')
                && $binary !== false
                && \in_array($binary, $parameters, strict: true)
            ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Every statement a data-removing uninstall issues, in order.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private static function uninstallStatements(): array
    {
        $statements = [];

        $connection = self::createStub(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters = []) use (&$statements): int {
                $statements[] = [$sql, $parameters];

                return 0;
            });

        self::plugin($connection)->uninstall(self::context(keepUserData: false));

        return $statements;
    }

    private static function plugin(Connection $connection): MerchantQuoteAgentPlugin
    {
        $container = self::createStub(ContainerInterface::class);
        $container
            ->method('get')
            ->willReturnCallback(static fn(string $id): ?object => match ($id) {
                Connection::class => $connection,
                SystemConfigService::class => self::createStub(SystemConfigService::class),
                default => null,
            });

        $plugin = new MerchantQuoteAgentPlugin(active: true, basePath: __DIR__);
        $plugin->setContainer($container);

        return $plugin;
    }

    private static function context(bool $keepUserData): UninstallContext
    {
        return new UninstallContext(
            new MerchantQuoteAgentPlugin(active: true, basePath: __DIR__),
            Context::createDefaultContext(),
            '6.7.0.0',
            '1.0.0',
            self::createStub(MigrationCollection::class),
            $keepUserData,
        );
    }
}
