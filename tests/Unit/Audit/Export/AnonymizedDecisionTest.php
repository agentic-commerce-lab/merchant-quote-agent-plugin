<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use MerchantQuoteAgentPlugin\Audit\Export\ExportPseudonym;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @mago-expect lint:too-many-methods
 * One test per classification decision the mapper makes -- pseudonymized,
 * dropped, kept verbatim, reshaped, gated behind the free-text flag -- and
 * splitting them into a second class would scatter one mapper's coverage
 * across two files for no reader's benefit.
 */
#[CoversClass(AnonymizedDecision::class)]
final class AnonymizedDecisionTest extends TestCase
{
    private const CUSTOMER_ID = '0191d3d0a0b071bd9c1a0d9d1a3f9f02';

    public function testEveryIdIsReplacedByItsPseudonymUnderAShorterName(): void
    {
        $row = self::export(self::record());

        self::assertSame(self::pseudonym()->of(self::CUSTOMER_ID), $row['customer']);
        self::assertNotContains(self::CUSTOMER_ID, array_map(strval(...), array_filter($row, is_scalar(...))));
        self::assertArrayNotHasKey('customerId', $row);
        self::assertArrayHasKey('quote', $row);
        self::assertArrayHasKey('salesChannel', $row);
        self::assertArrayHasKey('revision', $row);
        self::assertArrayHasKey('strategyVersion', $row);
    }

    /**
     * A closed vocabulary of four words describing plugin configuration, not
     * a shop identifier -- VERBATIM, alongside band and outcome, the same
     * shape. The property name is also the export key: VERBATIM is a plain
     * list, not a rename map, so there is nothing to rename.
     */
    public function testTheAssignmentSourceLeavesTheShopUnpseudonymized(): void
    {
        $row = self::export(self::record());

        self::assertSame('rule', $row['strategyAssignmentSource']);
    }

    public function testTheQuoteNumberNeverLeaves(): void
    {
        $row = self::export(self::record());

        self::assertArrayNotHasKey('quoteNumber', $row);
        self::assertStringNotContainsString('QU10042', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testTheAnalyticFieldsAreKeptAsTheyAre(): void
    {
        $row = self::export(self::record());

        self::assertSame('offered', $row['outcome']);
        self::assertSame('grant', $row['band']);
        self::assertSame(12.5, $row['discountPercentGranted']);
        self::assertSame('EUR', $row['currencyIso']);
        self::assertSame('unparsable-host', $row['modelHost']);
        self::assertSame('accepted', $row['terminalState']);
        self::assertSame(['updateQuote', 'recalculate'], $row['writes']);
        self::assertSame(1190.0, $row['totalGrossAfter']);
    }

    public function testFreeTextIsAbsentByDefaultAndPresentWithTheFlag(): void
    {
        $withheld = json_encode(self::export(self::record()), JSON_THROW_ON_ERROR);
        $included = json_encode(self::export(self::record(), freeText: true), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('anna.mueller@acme.example', $withheld);
        self::assertStringContainsString('anna.mueller@acme.example', $included);
    }

    public function testTheRawProposalIsGatedByTheSameFlagAsTheReply(): void
    {
        $default = self::export(self::record());
        $included = self::export(self::record(), freeText: true);

        self::assertArrayNotHasKey('rawProposal', $default);
        self::assertArrayNotHasKey('replyToBuyer', $default);
        self::assertArrayNotHasKey('violations', $default);
        self::assertArrayHasKey('rawProposal', $included);
        self::assertArrayHasKey('replyToBuyer', $included);
        self::assertArrayHasKey('violations', $included);
    }

    public function testTheStructuredAskSurvivesButTheModelsQuestionsDoNot(): void
    {
        $row = self::export(self::record());

        self::assertIsArray($row['interpretedAsks']);
        self::assertSame(['additionalDiscountPercent' => 15.0], $row['interpretedAsks']['price']);
        self::assertArrayNotHasKey('clarificationQuestions', $row['interpretedAsks']);
        self::assertArrayNotHasKey('humanReviewRequests', $row['interpretedAsks']);
    }

    public function testTheAccountHistoryBlockNeverLeavesEvenWithTheFlag(): void
    {
        foreach ([false, true] as $freeText) {
            $row = self::export(self::record(), $freeText);

            self::assertIsArray($row['historyReads']);
            self::assertSame(7, $row['historyReads']['quotesSeen']);
            self::assertSame(['orders'], $row['historyReads']['rounds']);
            self::assertStringNotContainsString('QU10042', json_encode($row['historyReads'], JSON_THROW_ON_ERROR));
        }
    }

    public function testAnErrorKeepsItsClassAndPlaceButNotItsMessage(): void
    {
        $default = self::export(self::record());
        $included = self::export(self::record(), freeText: true);

        self::assertSame([['class' => 'RuntimeException', 'at' => '/srv/Foo.php:12']], $default['errorChain']);
        self::assertIsArray($included['errorChain'][0]);
        self::assertArrayHasKey('message', $included['errorChain'][0]);
    }

    public function testEmptyColumnsStayNullRatherThanDisappearing(): void
    {
        $row = self::export(new QuoteDecisionRecord());

        self::assertNull($row['customer']);
        self::assertNull($row['outcome']);
        self::assertNull($row['interpretedAsks']);
        self::assertNull($row['historyReads']);
        self::assertNull($row['errorChain']);
        self::assertNull($row['terminalAt']);
    }

    public function testTimestampsAreIso8601(): void
    {
        $row = self::export(self::record());

        self::assertSame('2026-09-14T10:00:00+00:00', $row['terminalAt']);
    }

    /** @return array<string, mixed> */
    private static function export(QuoteDecisionRecord $record, bool $freeText = false): array
    {
        return AnonymizedDecision::of($record, self::pseudonym(), $freeText);
    }

    private static function pseudonym(): ExportPseudonym
    {
        return new ExportPseudonym('a-fixed-test-salt');
    }

    private static function record(): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = '0191d3d0a0b071bd9c1a0d9d1a3f9f00';
        $record->quoteId = '0191d3d0a0b071bd9c1a0d9d1a3f9f01';
        $record->customerId = self::CUSTOMER_ID;
        $record->salesChannelId = '0191d3d0a0b071bd9c1a0d9d1a3f9f03';
        $record->revisionVersionId = '0191d3d0a0b071bd9c1a0d9d1a3f9f04';
        $record->strategyVersionId = '0191d3d0a0b071bd9c1a0d9d1a3f9f05';
        $record->strategyAssignmentSource = 'rule';
        $record->quoteNumber = 'QU10042';
        $record->currencyIso = 'EUR';
        $record->outcome = 'offered';
        $record->band = 'grant';
        $record->discountPercentGranted = 12.5;
        $record->totalGrossAfter = 1190.0;
        $record->modelHost = 'unparsable-host';
        $record->terminalState = 'accepted';
        $record->terminalAt = new \DateTimeImmutable('2026-09-14T10:00:00+00:00');
        $record->writes = ['updateQuote', 'recalculate'];
        $record->rawProposal = '{"message":"Dear Anna, anna.mueller@acme.example, ..."}';
        $record->replyToBuyer = 'Hello Anna Mueller (anna.mueller@acme.example), account 10042 ...';
        $record->violations = ['The buyer anna.mueller@acme.example insists on 30%.'];
        $record->interpretedAsks = [
            'price' => ['additionalDiscountPercent' => 15.0],
            'clarificationQuestions' => ['Does anna.mueller@acme.example mean per unit?'],
            'humanReviewRequests' => ['Account 10042 wants to speak to a person.'],
        ];
        $record->historyReads = [
            'quotesSeen' => 7,
            'rounds' => [[
                'kind' => 'orders',
                'productId' => 'deadbeef',
                'result' => 'order 10009, quote QU10042, ...',
            ]],
        ];
        $record->errorChain = [
            [
                'class' => 'RuntimeException',
                'message' => 'anna.mueller@acme.example refused',
                'at' => '/srv/Foo.php:12',
            ],
        ];

        return $record;
    }
}
