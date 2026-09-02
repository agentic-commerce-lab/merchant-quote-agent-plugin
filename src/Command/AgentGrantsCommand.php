<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Command;

use MerchantQuoteAgentPlugin\Identity\Authorization\AgentGrantReaderInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operational off-switch for agent grants.
 *
 * Console-only by decision: revocation has to be possible, not self-service,
 * until a merchant asks for a storefront page. Echoing is allowed here — Mago's
 * no-debug-symbols rule exempts commands, and a console command that printed
 * nothing would be useless.
 */
#[AsCommand(name: 'merchant-quote-agent:agent-grants', description: 'List or revoke agent identity-linking grants')]
final class AgentGrantsCommand extends Command
{
    public function __construct(
        private readonly AgentGrantReaderInterface $grants,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('customerId', InputArgument::OPTIONAL, 'Filter by customer id (hex)');
        $this->addOption('revoke', null, InputOption::VALUE_REQUIRED, 'Revoke this client id for the given customer');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $customerId = $input->getArgument('customerId');
        $customerId = \is_string($customerId) && $customerId !== '' ? $customerId : null;
        $revoke = $input->getOption('revoke');

        if (\is_string($revoke) && $revoke !== '') {
            if ($customerId === null) {
                $io->error(
                    'Revoking needs a customer id: merchant-quote-agent:agent-grants <customerId> --revoke=<clientId>',
                );

                return Command::INVALID;
            }

            $count = $this->grants->revoke($customerId, $revoke);
            $message = \sprintf('Revoked %d grant(s) for %s.', $count, $revoke);
            $count === 0 ? $io->warning($message) : $io->success($message);

            return Command::SUCCESS;
        }

        $rows = $this->grants->grantsFor($customerId);

        if ($rows === []) {
            $io->writeln('No grants.');

            return Command::SUCCESS;
        }

        $io->table(['Customer', 'Agent (client id)', 'Scope', 'Expires', 'Revoked'], array_map(
            static fn(array $row): array => [
                $row['customer_id'],
                $row['client_id'],
                $row['scope'] === '' ? '-' : $row['scope'],
                date('Y-m-d H:i:s', $row['expires_at']),
                $row['revoked'] ? 'yes' : 'no',
            ],
            $rows,
        ));

        return Command::SUCCESS;
    }
}
