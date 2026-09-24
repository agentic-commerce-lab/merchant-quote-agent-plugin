<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Command;

use MerchantQuoteAgentPlugin\Audit\Export\DecisionExportStream;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Command\DecisionExportCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Only the three refusal tests: each returns before the repository is
 * touched. The two notice-on-stderr tests from the brief run
 * RepositoryIterator over a mocked EntityRepository, whose constructor calls
 * $repository->getDefinition()->hasAutoIncrement() -- null on a bare
 * createMock(EntityRepository::class), which is a fatal error, not a
 * failing assertion. Stubbing EntityDefinition to get past it would test
 * Shopware's paging, not our notices. The stderr-notice behaviour is
 * covered in Task 4 against a real repository.
 */
#[CoversClass(DecisionExportCommand::class)]
final class DecisionExportCommandTest extends TestCase
{
    public function testItRefusesWithoutADateRange(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute(['--from' => '2026-09-01'], ['capture_stderr_separately' => true]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('--from and --to', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay(), 'Refusal messages must not land on stdout.');
    }

    public function testItRefusesADateItCannotRead(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute(['--from' => 'last tuesday-ish', '--to' => '2026-10-01'], [
            'capture_stderr_separately' => true,
        ]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('could not be read as a date', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay(), 'Refusal messages must not land on stdout.');
    }

    public function testItRefusesARangeThatRunsBackwards(): void
    {
        $tester = new CommandTester($this->command());

        $exit = $tester->execute(['--from' => '2026-10-01', '--to' => '2026-09-01'], [
            'capture_stderr_separately' => true,
        ]);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('--to must be after --from', $tester->getErrorOutput());
        self::assertSame('', $tester->getDisplay(), 'Refusal messages must not land on stdout.');
    }

    private function command(): DecisionExportCommand
    {
        $repository = $this->createMock(EntityRepository::class);
        $config = $this->createMock(SystemConfigService::class);
        $config->method('get')->with(ExportPseudonym::CONFIG_KEY)->willReturn('a-fixed-test-salt');

        return new DecisionExportCommand(
            new DecisionExportStream($repository, $this->createMock(EntityRepository::class), $config),
        );
    }
}
