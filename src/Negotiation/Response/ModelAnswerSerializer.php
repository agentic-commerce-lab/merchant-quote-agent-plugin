<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Symfony AI's own structured-output serializer, with extra attributes turned
 * OFF. That one setting is the whole reason this class exists.
 *
 * The default is permissive: a model answer whose keys we do not recognise —
 * a JSON array, or the previous snake_case wire shape from a provider that
 * ignored the schema — quietly denormalizes into an all-defaults object. An
 * empty interpretation is a legitimate answer ("the buyer asked for nothing we
 * can act on"), so nothing downstream can tell that apart from a garbled one,
 * and the buyer would be left unanswered in silence. Refusing unknown keys
 * turns that into a ModelUnavailable, which escalates to a human.
 *
 * PhpDocExtractor is what reads `@param list<QuoteLinePrice>` off the DTO
 * constructors, so the nested lists map to objects rather than plain arrays.
 * Serializer attributes are deliberately NOT used for naming: the JSON schema
 * is generated from the property names, so the wire speaks camelCase and a
 * SerializedName would silently desynchronise the two.
 */
final class ModelAnswerSerializer extends Serializer
{
    public function __construct()
    {
        $propertyInfo = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);

        parent::__construct([
            new DateTimeNormalizer(),
            new BackedEnumNormalizer(),
            new ObjectNormalizer(
                classMetadataFactory: new ClassMetadataFactory(new AttributeLoader()),
                propertyTypeExtractor: $propertyInfo,
                defaultContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            ),
            new ArrayDenormalizer(),
        ], [new JsonEncoder()]);
    }
}
