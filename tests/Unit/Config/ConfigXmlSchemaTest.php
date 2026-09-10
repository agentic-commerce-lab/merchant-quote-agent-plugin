<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * `src/Resources/config/config.xml` against the oldest core we claim to
 * support, not the one in vendor/.
 *
 * `vendor/shopware/core` floats ahead of the support floor, so validating
 * against vendor/'s config.xsd proves nothing about the floor: an element the
 * floor rejects can be perfectly valid against a newer schema and still break
 * installation on the oldest supported shop (`<subtitle>`, added to the schema
 * later, did exactly this).
 *
 * The fixture is a vendored copy of `shopware/core` **v6.7.1.0**'s
 * System/SystemConfig/Schema/config.xsd — the floor itself. It used to be
 * 6.7.12.1's, described in this docblock as "the oldest supported core", which
 * transposed 6.7.1.2 and 6.7.12: that schema is the NEWEST of the supported
 * range, so the check was looser than it claimed. The two differ only by a
 * `cache-relevant` attribute this config.xml does not use, so the swap
 * tightened the guard without changing its verdict.
 *
 * If the support floor ever moves, replace this fixture with the new floor's
 * config.xsd and update FLOOR_TAG in CoreFloorCompatibilityTest with it.
 */
final class ConfigXmlSchemaTest extends TestCase
{
    public function testConfigXmlValidatesAgainstTheOldestSupportedCoresSchema(): void
    {
        $configPath = __DIR__ . '/../../../src/Resources/config/config.xml';
        $schemaPath = __DIR__ . '/../../Fixtures/Config/shopware-core-6.7.1.0-config.xsd';

        $document = new DOMDocument();
        self::assertTrue($document->load($configPath), "Could not load {$configPath} as XML.");

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $valid = $document->schemaValidate($schemaPath);
            $errors = libxml_get_errors();
        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }

        if (!$valid) {
            $messages = array_map(static fn(\LibXMLError $error): string => sprintf(
                'line %d: %s',
                $error->line,
                trim($error->message),
            ), $errors);

            self::fail(
                "config.xml does not validate against shopware/core 6.7.1.0's config.xsd "
                    . "(the oldest supported core):\n"
                    . implode("\n", $messages),
            );
        }

        self::assertTrue($valid);
    }
}
