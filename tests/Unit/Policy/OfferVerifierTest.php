<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\VerifyOfferInput;
use MerchantQuoteAgentPlugin\Policy\OfferVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferVerifierTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $verifyInput = new VerifyOfferInput(
            reference: QuoteSnapshot::fromArray($input['reference']),
            final: QuoteSnapshot::fromArray($input['final']),
            limits: QuoteLimits::fromArray($input['limits']),
            now: new \DateTimeImmutable($input['now']),
        );

        $violations = (new OfferVerifier())->verify($verifyInput);

        self::assertSame($expected, $violations, $description);
    }

    public static function fixtures(): iterable
    {
        yield from self::readFixtureFile('offer-verify.json');
        yield from self::readFixtureFile('offer-verify-compounding.json');
        yield from self::readFixtureFile('offer-verify-currency.json');
    }

    /**
     * #56, the verify-side mirror of PriceOfferCheck's missing lower bound: a
     * final total ABOVE the reference is a violation, not a pass. This is
     * deliberately over-eager for a mid-negotiation quantity increase, which
     * also raises the final total against a baseline that did not move — the
     * mirror of #49's documented quantity-reduction limitation — and it fails
     * the same safe way: a human sees it, nothing is under-charged.
     */
    public function testAFinalTotalAboveTheReferenceIsAViolation(): void
    {
        $verifyInput = new VerifyOfferInput(
            reference: QuoteSnapshot::fromArray([
                'currencyIso' => 'EUR',
                'totalNet' => 1000.0,
                'stateTechnicalName' => 'in_review',
                'lines' => [],
            ]),
            final: QuoteSnapshot::fromArray([
                'currencyIso' => 'EUR',
                'totalNet' => 1050.0,
                'stateTechnicalName' => 'in_review',
                'lines' => [],
            ]),
            limits: QuoteLimits::fromArray(['maxDiscountPercent' => 10, 'validityDays' => 14]),
            now: new \DateTimeImmutable('2026-07-22T12:00:00.000Z'),
        );

        $violations = (new OfferVerifier())->verify($verifyInput);

        self::assertSame(['total discount -5.0% raises the quote above its reference total'], $violations);
    }

    private static function readFixtureFile(string $filename): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/' . $filename),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $filename . ': ' . $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }
}
