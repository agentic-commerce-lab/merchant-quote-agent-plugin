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
