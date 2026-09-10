<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Proves the live acceptance assertion fails for each private figure, without a provider call. */
final class HistoryMessagePrivacyTest extends TestCase
{
    public function testCurrentOfferFiguresAreAllowed(): void
    {
        HistoryMessageFixture::assertPrivateMessage('Our formal quote offers 5% off: EUR 950.00, valid for 14 days.');
    }

    #[DataProvider('disclosures')]
    public function testAnyBriefFigureInTheNegotiateMessageFailsAcceptance(string $figure): void
    {
        $this->expectException(AssertionFailedError::class);
        HistoryMessageFixture::assertPrivateMessage('Our formal quote offers 5% off. Your account record: ' . $figure);
    }

    public function testEveryExpectedFigureIsActuallyInTheBrief(): void
    {
        $brief = HistoryMessageFixture::brief();
        preg_match_all('/\d{4}-\d{2}-\d{2}|\d+(?:\.\d+)?/', $brief, $figures);
        self::assertSame(HistoryMessageFixture::FIGURES, $figures[0], 'Every rendered figure needs an assertion.');
    }

    /** @return iterable<string, array{string}> */
    public static function disclosures(): iterable
    {
        foreach ([
            ...HistoryMessageFixture::FIGURES,
            '25.',
            '12, as recorded',
            '6,37%',
            '82,461.93',
            '82.461,93',
            '82 461,93',
            "82\u{202f}461,93",
            '22.04.2003',
            '04/22/2003',
            'April 22, 2003',
            '22 April 2003',
        ] as $figure) {
            yield $figure => [$figure];
        }
    }
}
