<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\NegotiationVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NegotiationVerifierTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $decision = NonPriceDecisionFixture::fromArray($input['decision']);
        $policy = NegotiationPolicy::fromArray($input['policy']);
        $snapshot = QuoteSnapshot::fromArray($input['snapshot']);

        $violations = (new NegotiationVerifier())->verify($decision, $policy, $snapshot);

        self::assertSame($expected, $violations, $description);
    }

    public static function fixtures(): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/negotiation-verify.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }
}
