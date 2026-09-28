<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Config;

use DOMDocument;
use MerchantQuoteAgentPlugin\Policy\Data\RoundingMode;
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

    public function testConfigXmlDeclaresNotifyBuyerOnEscalation(): void
    {
        $configPath = __DIR__ . '/../../../src/Resources/config/config.xml';
        $document = new DOMDocument();
        self::assertTrue($document->load($configPath));

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//input-field[name="notifyBuyerOnEscalation"]');
        self::assertNotNull($nodes);
        self::assertSame(1, $nodes->count());

        $field = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertSame('bool', $field->getAttribute('type'));

        $default = $xpath->query('defaultValue', $field)?->item(0)?->textContent;
        self::assertSame('true', $default, 'An escalation the buyer is never told about reads as a dead shop.');
    }

    /**
     * The assistant acts in the buyer's name, so per spec §6 it is opted into
     * rather than switched on the moment both plugins are installed. Nothing
     * else pins this: `RequestQuoteToolFactoryTest` mocks
     * `SystemConfigService::get()` to return a bool directly, so it would
     * still pass green if the reader's `=== true` became `!== false`.
     */
    public function testConfigXmlDeclaresAssistantQuoteRequestsDefaultOff(): void
    {
        $configPath = __DIR__ . '/../../../src/Resources/config/config.xml';
        $document = new DOMDocument();
        self::assertTrue($document->load($configPath));

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//input-field[name="assistantQuoteRequests"]');
        self::assertNotNull($nodes);
        self::assertSame(1, $nodes->count());

        $field = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertSame('bool', $field->getAttribute('type'));

        $default = $xpath->query('defaultValue', $field)?->item(0)?->textContent;
        self::assertSame('false', $default, 'The assistant acts for the buyer, so it must be opt-in.');
    }

    public function testTheNegotiationStrategyCardUsesTheSelectorComponent(): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(__DIR__ . '/../../../src/Resources/config/config.xml'));

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//component[name="negotiationStrategyId"]');
        self::assertNotNull($nodes);
        self::assertSame(1, $nodes->count());

        $component = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $component);
        self::assertSame('merchant-quote-agent-strategy-select', $component->getAttribute('name'));

        // `cache-relevant` exists in vendor's config.xsd but NOT in the
        // 6.7.1.0 floor schema this file is validated against.
        self::assertFalse($component->hasAttribute('cache-relevant'));
    }

    public function testTheFreeTextStrategyFieldIsGone(): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(__DIR__ . '/../../../src/Resources/config/config.xml'));

        $nodes = (new \DOMXPath($document))->query('//input-field[name="negotiationStrategy"]');
        self::assertNotNull($nodes);
        self::assertSame(0, $nodes->count());
    }

    /** The admin's options and RoundingMode::from() must agree, or a saved option takes the channel out of service. */
    public function testTheRoundingModeOptionsAreTheEnumsCasesAndDefaultToOff(): void
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(__DIR__ . '/../../../src/Resources/config/config.xml'));

        $xpath = new \DOMXPath($document);
        $field = $xpath->query('//input-field[name="roundingMode"]')?->item(0);
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertSame('single-select', $field->getAttribute('type'));
        self::assertSame('off', $xpath->query('defaultValue', $field)?->item(0)?->textContent);

        $nodes = $xpath->query('options/option/id', $field);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);
        $ids = [];
        foreach ($nodes as $node) {
            $ids[] = $node->textContent;
        }

        self::assertSame(array_map(static fn(RoundingMode $mode): string => $mode->value, RoundingMode::cases()), $ids);
    }
}
