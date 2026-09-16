<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Command;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use Override;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the agent's decision records for a date range to stdout as JSONL,
 * anonymized, so a merchant can send them to Shopware for the collective
 * strategy work #19 describes.
 *
 * A merchant action, never automatic: nothing schedules this and nothing calls
 * it. Sharing business records with a third party is a decision a person
 * makes, once, deliberately.
 *
 * JSONL goes to stdout and every notice to stderr, so
 * `... > september.jsonl` produces a file containing only records. The
 * exclusion notice is not optional: a merchant who does not know free text was
 * withheld will not know what they sent, and one who does not know it was
 * INCLUDED will not know what they sent either -- so both runs say which.
 *
 * The range is half-open, `--from` inclusive and `--to` exclusive. A bare date
 * parses to midnight, so an inclusive `--to` would quietly drop the last day
 * for everyone who typed one.
 *
 * What leaves and what does not is in docs/for-merchants.md, and the
 * classification that decides it is AnonymizedDecision's five lists.
 */
#[AsCommand(
    name: 'merchant-quote-agent:export',
    description: 'Export the agent\'s decision records for a date range as anonymized JSONL.',
)]
final class DecisionExportCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $decisions,
        private readonly SystemConfigService $systemConfig,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start of the range, inclusive (e.g. 2026-09-01).');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'End of the range, exclusive (e.g. 2026-10-01).');
        $this->addOption(
            'include-comments',
            null,
            InputOption::VALUE_NONE,
            'Also export free text: the agent\'s replies, the model\'s raw answers and questions, escalation prose'
            . ' and error messages. Withheld by default because a model can repeat whatever the buyer typed.',
        );
    }

    /** @throws \Random\RandomException */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $from = $input->getOption('from');
        $to = $input->getOption('to');

        if (!\is_string($from) || !\is_string($to) || $from === '' || $to === '') {
            $io->error(
                'Both --from and --to are required: merchant-quote-agent:export --from=2026-09-01 --to=2026-10-01',
            );

            return Command::INVALID;
        }

        try {
            $start = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
        } catch (\Exception $e) {
            $io->error(\sprintf('"%s" or "%s" could not be read as a date: %s', $from, $to, $e->getMessage()));

            return Command::INVALID;
        }

        if ($end <= $start) {
            $io->error(
                '--to must be after --from; the range is half-open, so September is --from=2026-09-01 --to=2026-10-01.',
            );

            return Command::INVALID;
        }

        $freeText = (bool) $input->getOption('include-comments');
        $written = $this->write($output, $start, $end, $freeText);

        $this->report($io, $written, $freeText);

        return Command::SUCCESS;
    }

    /** @throws \Random\RandomException */
    private function write(
        OutputInterface $output,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        bool $freeText,
    ): int {
        $pseudonym = ExportPseudonym::forShop($this->systemConfig);
        $iterator = new RepositoryIterator(
            $this->decisions,
            Context::createDefaultContext(),
            self::criteria($from, $to),
        );
        $written = 0;

        while (($result = $iterator->fetch()) !== null) {
            foreach ($result->getEntities() as $record) {
                if (!$record instanceof QuoteDecisionRecord) {
                    continue;
                }

                $output->writeln((string) json_encode(
                    AnonymizedDecision::of($record, $pseudonym, $freeText),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));
                ++$written;
            }
        }

        return $written;
    }

    private static function criteria(\DateTimeImmutable $from, \DateTimeImmutable $to): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('createdAt', [
            RangeFilter::GTE => $from->format(\DateTimeInterface::ATOM),
            RangeFilter::LT => $to->format(\DateTimeInterface::ATOM),
        ]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(500);

        return $criteria;
    }

    /** Notices go to stderr so a redirect of stdout captures only JSONL. */
    private function report(SymfonyStyle $io, int $written, bool $freeText): void
    {
        $errors = $io->getErrorStyle();
        $errors->writeln(\sprintf(
            '%d record(s). Customer, quote, channel, revision and strategy ids are pseudonymized with this shop\'s'
            . ' salt; names, addresses and the quote number are not exported at all.',
            $written,
        ));

        $errors->writeln(
            $freeText
                ? 'Free text IS included (--include-comments): the agent\'s replies, the model\'s raw answers and'
                . ' questions, escalation prose and error messages. These can repeat whatever the buyer typed.'
                : 'Free text is excluded: the agent\'s replies, the model\'s raw answers and questions, escalation prose'
                . ' and error messages. Pass --include-comments to include them.',
        );
    }
}
