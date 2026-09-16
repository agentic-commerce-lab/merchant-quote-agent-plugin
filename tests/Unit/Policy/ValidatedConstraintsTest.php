<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use PHPUnit\Framework\TestCase;

/**
 * A constraint attribute that nothing evaluates is worse than no constraint:
 * it reads as enforcement at the exact place a reviewer looks for it.
 *
 * Two real consumers reach a `Policy\Data` constraint, and they are not the
 * same one:
 *
 * - `ValidatorInterface::validate()` runs in exactly one place —
 *   `QuoteAgentSettingsFactory`, on the merchant's `NegotiationPolicy` — and
 *   `Assert\Valid` carries that run to `QuoteLimits` and on to
 *   `QuoteValueCeiling`.
 * - `Negotiation\Response\ResponseFormatFactory` (Symfony AI's structured-
 *   output schema generator) reflects the SAME attribute classes to build the
 *   JSON schema the extract call's `CommentInterpretation` answers under —
 *   confirmed by `ResponseFormatFactoryTest`, which fails without
 *   `InterpretedProductAddition::$quantity`'s `Assert\Positive` and
 *   `PriceAsk::$additionalDiscountPercent`'s `Assert\Range`. That reach
 *   extends through `CommentInterpretation` to `PriceAsk`, `StructuralAsks`'s
 *   `InterpretedLineChange` and `InterpretedProductAddition`, and
 *   `NegotiationAsks`'s `DeliveryAsk` and `PaymentAsk`.
 *
 * Every other `Policy\Data` DTO carried constraints that neither consumer
 * reaches: #56 found a negative `discountPercent` passing an `OfferedPrice`
 * annotated `Range(min: 0, max: 100)`, because nothing evaluates it —
 * `NegotiateResponse`'s schema is built from `OfferTerms`, a deliberate
 * constraint-free wire twin of `OfferedPrice` (see that class's docblock), not
 * from `OfferedPrice` itself. Those attributes — on `ProposedOffer`,
 * `OfferedPrice`, `QuoteSnapshot` and `QuoteLineSnapshot` — are deleted.
 *
 * The check is on the SOURCE rather than on reflection so that it cannot be
 * defeated by importing a constraint under a different alias, and so that it
 * needs no class to be autoloadable.
 */
final class ValidatedConstraintsTest extends TestCase
{
    private const VALIDATED = [
        'Policy/Data/NegotiationPolicy.php',
        'Policy/Data/QuoteLimits.php',
        'Policy/Data/QuoteValueCeiling.php',
        'Policy/Data/PriceAsk.php',
        'Policy/Data/DeliveryAsk.php',
        'Policy/Data/PaymentAsk.php',
        'Policy/Data/InterpretedLineChange.php',
        'Policy/Data/InterpretedProductAddition.php',
    ];

    public function testOnlyTheValidatedTreeReferencesSymfonyConstraints(): void
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $carriers = [];

        foreach (self::phpFiles($root) as $file) {
            if (str_contains((string) file_get_contents($file), 'Symfony\\Component\\Validator\\Constraints')) {
                $carriers[] = str_replace($root . '/', '', $file);
            }
        }

        sort($carriers);
        $expected = self::VALIDATED;
        sort($expected);

        self::assertSame(
            $expected,
            $carriers,
            'A constraint outside the validated tree is decoration; one missing from it is a check that stopped running.',
        );
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
