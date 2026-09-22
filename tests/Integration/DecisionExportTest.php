<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Command\DecisionExportCommand;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #19's third Done-when, which #34 carries:
 *
 *   "The export contains no raw identifier; a test greps for the fixture's
 *   email and customer number and finds neither."
 *
 * A grep passes trivially against a fixture that never held the string, so
 * four things here stop it meaning nothing:
 *
 *  1. The fixture plants the email and the customer number in every column
 *     that can really carry them -- the agent's reply, the model's raw
 *     answer, the escalation prose, the model's human-review question, the
 *     rendered account-history block and an exception message -- plus a real
 *     customerId and a real quote number in their own columns.
 *  2. testTheSameFixtureDoesLeakItWhenFreeTextIsAskedFor is the positive
 *     control: the same record exported with --include-comments DOES contain
 *     the email. If the fixture failed to carry it, or the exporter emitted
 *     nothing, or this were greping the wrong stream, that assertion fails.
 *  3. The default run asserts the analytic fields ARE present, so the test
 *     cannot pass by exporting a blank line.
 *  4. --include-comments is asserted not to widen anything else: the flag
 *     adds free text, it never turns anonymization off.
 *
 * `createdAt` is stamped explicitly and the export range narrowed to that one
 * day (2031-05-05), rather than the brief's wide --from=2000-01-01
 * --to=2100-01-01: other agents run this suite concurrently against the same
 * shop, `merchant_quote_agent_decision` already holds rows from them, and a
 * wide range would hand firstRow() a foreign record.
 *
 * @mago-expect lint:too-many-methods
 * Nine cases (the four Done-when guarantees above, the two stderr-notice cases
 * Task 3 could not cover, the JSONL-stream sanity check, and the two for the
 * --outcome filter #177 needs to review its silent passes) share five small
 * private helpers -- seed, seedSilentPass, export, runExport and firstRow --
 * rather than duplicating fixture and command-invocation code across files.
 */
final class DecisionExportTest extends IntegrationTestCase
{
    private const EMAIL = 'anna.mueller@acme.example';

    private const CUSTOMER_NUMBER = 'KD-10042-X';

    private const QUOTE_NUMBER = 'QU-99001-Z';

    private const CREATED_AT = '2031-05-05T12:00:00+00:00';

    public function testTheDefaultExportContainsNoRawIdentifier(): void
    {
        $customerId = Uuid::randomHex();
        $this->seed($customerId);

        $jsonl = $this->export();

        self::assertNotSame('', $jsonl, 'Nothing was exported, so the grep below would prove nothing.');
        self::assertStringNotContainsString(self::EMAIL, $jsonl);
        self::assertStringNotContainsString(self::CUSTOMER_NUMBER, $jsonl);
        self::assertStringNotContainsString(self::QUOTE_NUMBER, $jsonl);
        self::assertStringNotContainsString($customerId, $jsonl);
        self::assertStringNotContainsString('Anna Mueller', $jsonl);
    }

    public function testTheSameFixtureDoesLeakItWhenFreeTextIsAskedFor(): void
    {
        $this->seed(Uuid::randomHex());

        $jsonl = $this->export(freeText: true);

        self::assertStringContainsString(
            self::EMAIL,
            $jsonl,
            'The positive control failed: the fixture did not carry the email into the export at all, so the'
            . ' default run finding no email proves nothing.',
        );
    }

    public function testTheDefaultExportStillCarriesWhatTheExportIsFor(): void
    {
        $this->seed(Uuid::randomHex());

        $row = $this->firstRow($this->export());

        self::assertSame('offered', $row['outcome']);
        self::assertSame('grant', $row['band']);
        self::assertSame(12.5, $row['discountPercentGranted']);
        self::assertSame('accepted', $row['terminalState']);
        self::assertIsString($row['customer']);
        self::assertIsString($row['quote']);
    }

    public function testTheFlagWidensFreeTextAndNothingElse(): void
    {
        $customerId = Uuid::randomHex();
        $this->seed($customerId);

        $jsonl = $this->export(freeText: true);

        self::assertStringNotContainsString($customerId, $jsonl);
        self::assertStringNotContainsString(self::QUOTE_NUMBER, $jsonl);
        self::assertStringNotContainsString(self::CUSTOMER_NUMBER, $this->firstRow($jsonl)['customer'] ?? '');
        self::assertStringNotContainsString('order 10009', $jsonl, 'The account history block never leaves.');
    }

    public function testTheJsonlStreamIsCleanEnoughToRedirect(): void
    {
        $this->seed(Uuid::randomHex());

        foreach (explode("\n", trim($this->export())) as $line) {
            self::assertIsArray(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function testTheDefaultRunNoticesTheExclusionOnStderrOnly(): void
    {
        $this->seed(Uuid::randomHex());

        $tester = $this->runExport();

        self::assertStringContainsString('--include-comments', $tester->getErrorOutput());
        self::assertStringContainsString('excluded', $tester->getErrorOutput());
        self::assertStringNotContainsString(self::EMAIL, $tester->getErrorOutput());

        // The command's contract: records on stdout, notices on stderr, so a
        // merchant can redirect stdout to a file and get only records.
        self::assertStringNotContainsString('excluded', $tester->getDisplay());
        self::assertStringContainsString(self::CREATED_AT, $tester->getDisplay());
    }

    public function testTheIncludeCommentsRunNoticesInclusionWithoutTheExclusionWord(): void
    {
        $this->seed(Uuid::randomHex());

        $tester = $this->runExport(freeText: true);

        self::assertStringNotContainsString('excluded', $tester->getErrorOutput());
        self::assertStringContainsString('free text', strtolower($tester->getErrorOutput()));
    }

    public function testTheOutcomeFilterNarrowsTheFileAndSaysSo(): void
    {
        // The review #177 asks for: read the passes that decided a customer's
        // comment held no ask, and check they were all pleasantries. Before
        // `--outcome` that meant exporting everything and grepping; before
        // `buyer_ask` the comment was not in the file at all.
        $this->seed(Uuid::randomHex());
        $this->seedSilentPass();

        $tester = $this->runExport(freeText: true, outcome: 'nothing_to_do');
        $rows = array_map(static fn(string $line): array => json_decode(
            $line,
            true,
            flags: JSON_THROW_ON_ERROR,
        ), array_filter(explode("\n", trim($tester->getDisplay()))));

        self::assertNotSame([], $rows, 'Nothing was exported, so the filter below proves nothing.');

        // Every row, not just the first, and no count: other agents run this
        // suite against the same shop and seed this same day, so the honest
        // assertion is that the `offered` row above cannot be among these.
        foreach ($rows as $row) {
            self::assertSame('nothing_to_do', $row['outcome']);
        }

        self::assertContains(
            'Nice, thanks!',
            array_column($rows, 'buyerAsk'),
            'The comment the pass passed over is the point of reading these rows at all.',
        );

        // A filtered file is indistinguishable from an unfiltered one, so the
        // run has to say which it produced -- the same reason the free-text
        // notice is not optional.
        self::assertStringContainsString('nothing_to_do', $tester->getErrorOutput());
    }

    public function testTheBuyerCommentStaysBehindWithoutIncludeComments(): void
    {
        $this->seedSilentPass();

        $row = $this->firstRow($this->export(outcome: 'nothing_to_do'));

        self::assertSame('nothing_to_do', $row['outcome']);
        self::assertArrayNotHasKey(
            'buyerAsk',
            $row,
            'The buyer\'s own words are free text: they leave only when a merchant asks for free text.',
        );
    }

    /** A pass that read a comment, found no ask in it and answered with silence (#177). */
    private function seedSilentPass(): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $repository->create([[
            'id' => Uuid::randomHex(),
            'quoteId' => Uuid::randomHex(),
            'customerId' => Uuid::randomHex(),
            'salesChannelId' => Uuid::randomHex(),
            'currencyIso' => 'EUR',
            'triggerReason' => 'comment_written',
            'attempt' => 0,
            'outcome' => 'nothing_to_do',
            'createdAt' => self::CREATED_AT,
            'buyerAsk' => 'Nice, thanks!',
        ]], Context::createDefaultContext());
    }

    private function seed(string $customerId): void
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $repository->create(
            [[
                'id' => Uuid::randomHex(),
                'quoteId' => Uuid::randomHex(),
                'customerId' => $customerId,
                'salesChannelId' => Uuid::randomHex(),
                'quoteNumber' => self::QUOTE_NUMBER,
                'currencyIso' => 'EUR',
                'triggerReason' => 'comment_written',
                'attempt' => 0,
                'band' => 'grant',
                'outcome' => 'offered',
                'discountPercentGranted' => 12.5,
                'terminalState' => 'accepted',
                'durationMs' => 1234,
                'errorClass' => 'RuntimeException',
                // createdAt is stamped explicitly: CreatedAtFieldSerializer only
                // defaults it to now when the payload omits it, and this test needs
                // a deterministic day to export a narrow, race-free range from.
                'createdAt' => self::CREATED_AT,
                // Every field below can really carry what the buyer typed: the
                // model is shown their message and is free to repeat it.
                'replyToBuyer' =>
                    'Hello Anna Mueller, regarding ' . self::EMAIL . ' (customer ' . self::CUSTOMER_NUMBER . ').',
                'rawProposal' =>
                    '{"action":"offer","message":"Anna Mueller, '
                        . self::EMAIL
                        . ', customer '
                        . self::CUSTOMER_NUMBER
                        . '"}',
                'violations' => ['Anna Mueller (' . self::EMAIL . ') insists on 30%.'],
                'interpretedAsks' => [
                    'price' => ['additionalDiscountPercent' => 15.0],
                    'humanReviewRequests' => ['Customer ' . self::CUSTOMER_NUMBER . ' wants to speak to a person.'],
                ],
                'historyReads' => [
                    'quotesSeen' => 7,
                    'rounds' => [[
                        'kind' => 'orders',
                        'productId' => Uuid::randomHex(),
                        'result' => 'order 10009, quote ' . self::QUOTE_NUMBER . ', Anna Mueller, ' . self::EMAIL,
                    ]],
                ],
                'errorChain' => [[
                    'class' => 'RuntimeException',
                    'message' => 'The provider rejected the prompt quoting ' . self::EMAIL,
                    'at' => '/srv/Foo.php:12',
                ]],
            ]],
            Context::createDefaultContext(),
        );
    }

    private function export(bool $freeText = false, ?string $outcome = null): string
    {
        return $this->runExport($freeText, $outcome)->getDisplay();
    }

    private function runExport(bool $freeText = false, ?string $outcome = null): CommandTester
    {
        // The container's own instance, not Application::find(): FrameworkBundle's
        // console Application is not a dependency of this plugin, and the
        // command needs nothing an Application adds.
        $tester = new CommandTester(static::getContainer()->get(DecisionExportCommand::class));

        $options = ['--from' => '2031-05-05', '--to' => '2031-05-06'];

        if ($freeText) {
            $options['--include-comments'] = true;
        }

        if ($outcome !== null) {
            $options['--outcome'] = $outcome;
        }

        $tester->execute($options, ['capture_stderr_separately' => true]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    /** @return array<string, mixed> */
    private function firstRow(string $jsonl): array
    {
        $lines = array_values(array_filter(explode("\n", trim($jsonl))));
        self::assertNotSame([], $lines, 'The export wrote no rows.');

        $row = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }
}
