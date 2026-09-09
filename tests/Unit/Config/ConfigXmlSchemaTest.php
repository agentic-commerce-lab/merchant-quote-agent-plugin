<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use DOMDocument;
use PHPUnit\Framework\TestCase;

/**
 * `src/Resources/config/config.xml` against the oldest core we claim to
 * support, not the one in vendor/.
 *
 * `vendor/shopware/core` floats ahead of the support floor stated for this
 * plugin (Shopware 6.7.1.2–6.7.12), so validating against vendor/'s
 * config.xsd proves nothing about the floor: a card element the floor
 * rejects can be perfectly valid against a newer schema and still break
 * installation on the oldest supported shop (`<subtitle>`, added to the
 * schema after 6.7.12, did exactly this). The fixture below is a vendored
 * copy of `shopware/core` v6.7.12.1's
 * System/SystemConfig/Schema/config.xsd, fetched from a live 6.7.12 shop.
 * If the support floor ever moves, replace this fixture with that new
 * floor's config.xsd.
 */
final class ConfigXmlSchemaTest extends TestCase
{
    public function testConfigXmlValidatesAgainstTheOldestSupportedCoresSchema(): void
    {
        $configPath = __DIR__ . '/../../../src/Resources/config/config.xml';
        $schemaPath = __DIR__ . '/../../Fixtures/Config/shopware-core-6.7.12.1-config.xsd';

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
                "config.xml does not validate against shopware/core 6.7.12.1's config.xsd "
                    . "(the oldest supported core):\n"
                    . implode("\n", $messages),
            );
        }

        self::assertTrue($valid);
    }
}
