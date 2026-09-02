<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Command;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Identity\AgentAccessFlags;
use Override;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Turns the allow-any-agent switch on or off for one sales channel, and reports
 * where it is on.
 *
 * Console-only on purpose: while it is on, any agent that publishes a fetchable,
 * signed profile can transact on that channel, which is a decision for whoever
 * runs the shop rather than a setting to leave on a merchant's settings screen.
 * The allowlists themselves are editable in the Administration.
 */
#[AsCommand(
    name: 'merchant-quote-agent:allow-any-agent',
    description: 'Show, or set per sales channel, whether any agent presenting a signed profile is admitted.',
)]
final class AllowAnyAgentCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfig,
        private readonly AgentAccessFlags $flags,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            'salesChannelId',
            InputArgument::OPTIONAL,
            'Sales channel to change; omit to list every channel.',
        );
        $this->addOption(
            'on',
            null,
            InputOption::VALUE_NONE,
            'Admit any agent presenting a fetchable, signed profile.',
        );
        $this->addOption('off', null, InputOption::VALUE_NONE, 'Restrict to the channel\'s allowlists again.');
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $salesChannelId = $input->getArgument('salesChannelId');
        $on = (bool) $input->getOption('on');
        $off = (bool) $input->getOption('off');

        if (!\is_string($salesChannelId) || $salesChannelId === '') {
            if ($on || $off) {
                $io->error('--on and --off need a sales channel id.');

                return Command::FAILURE;
            }

            return $this->list($io);
        }

        if ($on === $off) {
            $io->error('Pass exactly one of --on or --off.');

            return Command::FAILURE;
        }

        $channels = $this->channels();
        if (!\array_key_exists($salesChannelId, $channels)) {
            $io->error(\sprintf('Unknown sales channel "%s". Run without arguments to list them.', $salesChannelId));

            return Command::FAILURE;
        }

        $this->systemConfig->set(AgentAccessFlags::ALLOW_ANY_AGENT_KEY, $on, $salesChannelId);

        if ($on) {
            $io->warning(\sprintf(
                'Sales channel "%s" now admits any agent that presents a fetchable, signed profile. Turn it off when'
                . ' you are done: --off.'
                . ' On a long-running worker (FrankenPHP, RoadRunner) this only takes effect for the first request'
                . ' that worker serves and then silently stops applying to later ones -- restart the worker after'
                . ' changing this flag, and do not conclude from one working request that every later one is'
                . ' covered.',
                $channels[$salesChannelId],
            ));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Sales channel "%s" is restricted to its allowlists again.', $channels[$salesChannelId]));

        return Command::SUCCESS;
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    private function list(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->channels() as $id => $name) {
            $rows[] = [$name, $id, $this->flags->allowAnyAgent($id) ? 'on' : 'off'];
        }

        $io->table(['Sales channel', 'Id', 'Allow any agent'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     *
     * @throws \Doctrine\DBAL\Exception
     */
    private function channels(): array
    {
        $channels = [];

        /** @var array{id: string, name: string} $row */
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sc.id)) AS id, COALESCE(t.name, LOWER(HEX(sc.id))) AS name'
            . ' FROM sales_channel sc'
            . ' LEFT JOIN sales_channel_translation t ON t.sales_channel_id = sc.id'
            . ' WHERE sc.active = 1 GROUP BY sc.id, t.name ORDER BY name',
        ) as $row) {
            $channels[$row['id']] = $row['name'];
        }

        return $channels;
    }
}
