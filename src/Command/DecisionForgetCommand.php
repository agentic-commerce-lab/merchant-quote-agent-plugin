<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Command;

use MerchantQuoteAgentPlugin\Audit\DecisionEraserInterface;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Answers one buyer's erasure request against the agent's decision records.
 *
 * The records outlive the negotiation on purpose -- they are how a merchant
 * explains what the agent did, and since #177 how they check a pass that
 * decided a comment held no ask -- so nothing expires them and nothing reacts
 * to a customer being deleted. A person asking to be forgotten is a request a
 * merchant answers deliberately, which is this command.
 *
 * Irreversible, and asks first for that reason. `--force` is for the automation
 * a merchant builds around their own erasure process; there is no dry run
 * because the count is the only thing a dry run could report and the
 * confirmation already names it.
 *
 * What survives is what the merchant decided: bands, totals, outcomes, timings.
 * What goes is everything a person could be recognised in. DecisionEraser owns
 * that list.
 */
#[AsCommand(
    name: 'merchant-quote-agent:forget',
    description: 'Remove one customer\'s words and id from the agent\'s decision records, keeping the decisions.',
)]
final class DecisionForgetCommand extends Command
{
    public function __construct(
        private readonly DecisionEraserInterface $eraser,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            'customerId',
            InputArgument::REQUIRED,
            'The customer\'s id, as it appears in the shop\'s customer table (32 hex characters).',
        );
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask for confirmation.');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $customerId = $input->getArgument('customerId');

        if (!\is_string($customerId) || !preg_match('/^[0-9a-f]{32}$/i', $customerId)) {
            $io->error('The customer id must be 32 hex characters, as stored in the shop\'s customer table.');

            return Command::INVALID;
        }

        if (
            !(bool) $input->getOption('force')
            && !$io->confirm(
                'This permanently removes that customer\'s comments, the agent\'s replies to them and their id from'
                . ' every decision record. The decisions themselves stay. Continue?',
                false,
            )
        ) {
            $io->writeln('Nothing was changed.');

            return Command::SUCCESS;
        }

        $changed = $this->eraser->forget($customerId);

        // Zero is an answer, not a failure: a customer the agent never
        // negotiated with has nothing here, and a merchant answering an
        // erasure request needs to be able to say so.
        $io->success(\sprintf('%d decision record(s) no longer identify that customer.', $changed));

        return Command::SUCCESS;
    }
}
