<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\BundleDecider;
use MerchantQuoteAgentPlugin\Policy\Data\ArrayMapper;
use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BundleDeciderTest extends TestCase
{
    #[DataProvider('fixtures')]
    public function testMatchesTsFixture(string $description, array $input, array $expected): void
    {
        $snapshot = QuoteSnapshot::fromArray($input['snapshot']);
        $policy = ArrayMapper::mapObject(BundlePolicy::class, $input['policy']);
        $priceLimits = QuoteLimits::fromArray($input['priceLimits']);

        $decision = (new BundleDecider())->decide($snapshot, $policy, $priceLimits);

        self::assertSame($expected['band'], $decision->band->value, $description);
        self::assertEquals(
            $expected['grantedDiscountPercent'] ?? null,
            $decision->grantedDiscountPercent,
            $description,
        );
        self::assertSame($expected['appliedTier']['minQty'] ?? null, $decision->appliedTier?->minQty, $description);
    }

    public static function fixtures(): iterable
    {
        $cases = json_decode(
            file_get_contents(__DIR__ . '/../../Fixtures/Policy/bundle-decision.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($cases as $case) {
            yield $case['description'] => [$case['description'], $case['input'], $case['expected']];
        }
    }
}
