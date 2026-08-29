<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use PHPUnit\Framework\TestCase;

/**
 * src/Negotiation/ must stay Shopware-free apart from the two exceptions the
 * bridge's own contract forces on it. The negotiation engine is the piece most
 * likely to be reused or tested outside a kernel, and an accidental
 * SystemConfigService import would end that quietly.
 */
final class NamespacePurityTest extends TestCase
{
    private const ALLOWED_SHOPWARE_IMPORTS = [
        // IllegalTransitionException is what QuoteGatewayInterface::transition
        // throws; catching it is the documented idempotency guard.
        'Shopware\\Core\\System\\StateMachine\\Exception\\IllegalTransitionException',
    ];

    public function testNothingInNegotiationImportsShopwareBeyondTheAllowedList(): void
    {
        $offenders = [];

        foreach (self::phpFiles(__DIR__ . '/../../../src/Negotiation') as $file) {
            preg_match_all('/^use ([^;]+);/m', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $import) {
                if (
                    str_starts_with($import, 'Shopware\\')
                    && !\in_array($import, self::ALLOWED_SHOPWARE_IMPORTS, strict: true)
                ) {
                    $offenders[] = basename($file) . ' imports ' . $import;
                }
            }
        }

        self::assertSame([], $offenders);
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
