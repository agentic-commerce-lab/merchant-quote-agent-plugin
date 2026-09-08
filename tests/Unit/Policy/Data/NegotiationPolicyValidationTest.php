<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy\Data;

use MerchantQuoteAgentPlugin\Policy\Data\BundlePolicy;
use MerchantQuoteAgentPlugin\Policy\Data\NegotiationPolicy;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteValueCeiling;
use MerchantQuoteAgentPlugin\Policy\Data\VolumeTier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The Assert attributes on the policy objects have always been declared and
 * never run. Issue #5 runs them, and a nested constraint only fires when the
 * property holding the object is marked Assert\Valid.
 */
final class NegotiationPolicyValidationTest extends TestCase
{
    public function testAnOutOfRangePriceCapIsReportedAtItsNestedPath(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(maxDiscountPercent: 150.0));

        self::assertSame(['price.maxDiscountPercent'], self::paths(self::validator()->validate($policy)));
    }

    public function testAnOutOfRangeVolumeTierIsReportedThroughTwoLevelsOfNesting(): void
    {
        $policy = new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 5.0),
            bundle: new BundlePolicy(volumeTiers: [new VolumeTier(minQty: 10, discountPercent: 150.0)]),
        );

        self::assertSame(['bundle.volumeTiers[0].discountPercent'], self::paths(self::validator()->validate($policy)));
    }

    public function testABadCurrencyIsReportedWithItsPath(): void
    {
        $policy = new NegotiationPolicy(price: new QuoteLimits(
            maxDiscountPercent: 5.0,
            valueCeiling: new QuoteValueCeiling(net: 100.0, currencyIso: 'NOPE'),
        ));

        self::assertSame(['price.valueCeiling.currencyIso'], self::paths(self::validator()->validate($policy)));
    }

    public function testAValidPolicyReportsNothing(): void
    {
        $policy = new NegotiationPolicy(
            price: new QuoteLimits(maxDiscountPercent: 5.0),
            bundle: new BundlePolicy(volumeTiers: [new VolumeTier(minQty: 10, discountPercent: 7.5)]),
        );

        self::assertSame([], self::paths(self::validator()->validate($policy)));
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    /**
     * @param \Symfony\Component\Validator\ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface> $violations
     *
     * @return list<string>
     */
    private static function paths(iterable $violations): array
    {
        $paths = [];

        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }
}
