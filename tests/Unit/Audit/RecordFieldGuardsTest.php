<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Protection;

/**
 * Both attributes this pins fail OPEN when mistyped, which is why they need a
 * test rather than a comment.
 *
 * `AttributeEntityCompiler` recognises only the literal keys 'admin-api' and
 * 'store-api'; an unrecognised or misspelt key yields an empty ApiAware source
 * list, and ApiAware expands an empty list to BOTH /api/ and /store-api/. A
 * one-character typo would silently publish the raw model proposal and the
 * merchant's authority limits to the Store API.
 *
 * A missing Protection attribute is quieter still: that field simply becomes
 * PATCH-able through the admin API, with nothing to notice.
 */
final class RecordFieldGuardsTest extends TestCase
{
    private const ADMIN_ONLY = ['admin-api' => true, 'store-api' => false];

    public function testEveryFieldIsAdminApiOnly(): void
    {
        foreach (self::attributes(Field::class) as $property => $field) {
            self::assertSame(
                self::ADMIN_ONLY,
                $field->api,
                sprintf('%s must be admin-api only; any other shape falls open to both APIs.', $property),
            );
        }
    }

    public function testEveryFieldIsWriteProtectedToSystemScope(): void
    {
        $protections = self::attributes(Protection::class);

        foreach (array_keys(self::attributes(Field::class)) as $property) {
            self::assertArrayHasKey(
                $property,
                $protections,
                sprintf('%s has no #[Protection]; it is PATCH-able through the admin API.', $property),
            );
            self::assertSame([Protection::SYSTEM_SCOPE], $protections[$property]->write, $property);
        }
    }

    public function testTheRecordHasFieldsAtAll(): void
    {
        // Guards the two tests above against passing vacuously if the
        // reflection ever stops finding attributes.
        self::assertNotEmpty(self::attributes(Field::class));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return array<string, T>
     */
    private static function attributes(string $attribute): array
    {
        $found = [];

        foreach ((new \ReflectionClass(QuoteDecisionRecord::class))->getProperties() as $property) {
            $instances = $property->getAttributes($attribute);

            if ($instances !== []) {
                $found[$property->getName()] = $instances[0]->newInstance();
            }
        }

        return $found;
    }
}
