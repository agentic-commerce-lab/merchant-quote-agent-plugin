<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationProposal;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate decision, which is now the price decision: the delivery,
 * payment and bundle cases left this fixture along with those dimensions.
 * AskGate escalates every non-price ask before the decider is reached, so the
 * non-price decision it used to aggregate was always a granting one.
 */
final class NegotiationDeciderTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $snapshot = QuoteSnapshot::fromArray($input['snapshot']);
        $policy = NegotiationPolicy::fromArray($input['policy']);
        $proposal = $input['proposal'] === null ? null : NegotiationProposal::fromArray($input['proposal']);

        $decision = (new NegotiationDecider())->decide($snapshot, $policy, $proposal);

        self::assertSame($expected['overall'], $decision->overall->value, $description);
        self::assertSame($expected['price']['kind'], $decision->price->kind->value, $description);
        self::assertSame(
            $this->escalationPrefixes($expected['escalationReasons']),
            $this->escalationPrefixes($decision->escalationReasons),
            $description,
        );
    }

    /** @param list<string> $reasons */
    private function escalationPrefixes(array $reasons): array
    {
        return array_map(static fn(string $r): string => explode(':', $r)[0], $reasons);
    }

    public static function fixtures(): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/negotiation-decision-aggregate.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }
}
