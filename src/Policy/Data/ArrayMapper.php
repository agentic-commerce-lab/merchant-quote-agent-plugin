<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\MapperBuilder;

/**
 * Maps decoded-JSON fixture data onto the leaf DTOs (those whose
 * constructor shape already matches the wire shape 1:1) via valinor's
 * reflection-based mapper — most DTOs need no `fromArray` at all. The
 * handful that regroup originally-flat TS fields into nested value objects
 * (to satisfy the 5-parameter constructor gate — QuoteLimits,
 * QuoteLineSnapshot, QuoteSnapshot, CommentInterpretation,
 * NegotiationProposal) still hand-write `fromArray`, delegating their leaf
 * sub-objects to this mapper and narrowing their few directly-reshaped
 * scalars via RequiredShape/OptionalShape.
 */
final class ArrayMapper
{
    private static ?MapperBuilder $builder = null;

    /**
     * @template T of object
     * @param class-string<T> $class
     * @throws MappingError
     * @return T
     */
    public static function mapObject(string $class, array $source): object
    {
        return self::builder()->mapper()->map($class, $source);
    }

    private static function builder(): MapperBuilder
    {
        // Reshaping DTOs (see class docblock) map the same flat source array
        // into more than one leaf sub-object, so each individual mapping
        // legitimately sees keys that belong to a sibling sub-object.
        return self::$builder ??= (new MapperBuilder())->allowSuperfluousKeys();
    }
}
