<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Strategy;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Applies a Criteria to an in-memory row set the way the real DAL would, so
 * StrategyAssignmentResolverTest's doubled repositories can prove the
 * resolver asked for the right thing instead of merely handing back whatever
 * rows they were constructed with.
 *
 * This codebase's usual repository double (see StrategyResolverTest) ignores
 * the Criteria entirely and returns every row it was built with, in
 * construction order. That is fine for a test that doesn't care about
 * filtering or sorting, but it means a resolver that filtered on a
 * misspelled field or sorted the wrong way would still pass every
 * assertion -- the bug this class exists to make visible again. It actually
 * filters and sorts, so a wrong Criteria fails here the same way it would
 * against a real database.
 *
 * Deliberately narrow: it understands exactly the Criteria features
 * StrategyAssignmentResolver uses -- id lookup, EqualsFilter, EqualsAnyFilter
 * (including a `null` element), ascending/descending FieldSorting with
 * MySQL's null ordering, and a limit -- and throws on anything else. A silent
 * fallback for an unrecognised filter or sorting would reopen exactly the
 * hole this class exists to close.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class against a threshold of 10. Filtering,
 * sorting, id lookup and limiting are four independent concerns and each
 * throws on what it does not recognise, which is guard clauses, not one
 * complex method.
 */
final class CriteriaFilter
{
    /**
     * @param list<object> $rows
     *
     * @return list<object>
     */
    public static function apply(array $rows, Criteria $criteria): array
    {
        $ids = $criteria->getIds();

        if ($ids !== []) {
            $rows = array_values(array_filter($rows, static fn(object $row): bool => \in_array(
                self::value($row, 'id'),
                $ids,
                true,
            )));
        }

        foreach ($criteria->getFilters() as $filter) {
            $rows = array_values(array_filter($rows, static fn(object $row): bool => self::matches($row, $filter)));
        }

        $sorting = $criteria->getSorting();

        if ($sorting !== []) {
            usort($rows, static fn(object $a, object $b): int => self::compare($a, $b, $sorting));
        }

        $limit = $criteria->getLimit();

        if ($limit !== null) {
            $rows = \array_slice($rows, 0, $limit);
        }

        return $rows;
    }

    private static function matches(object $row, Filter $filter): bool
    {
        if ($filter instanceof EqualsFilter) {
            return self::value($row, $filter->getField()) === $filter->getValue();
        }

        if ($filter instanceof EqualsAnyFilter) {
            return \in_array(self::value($row, $filter->getField()), $filter->getValue(), true);
        }

        throw new \LogicException('CriteriaFilter does not support ' . $filter::class . '.');
    }

    /** @param list<FieldSorting> $sorting */
    private static function compare(object $a, object $b, array $sorting): int
    {
        foreach ($sorting as $rule) {
            if (!$rule instanceof FieldSorting) {
                throw new \LogicException('CriteriaFilter does not support ' . $rule::class . ' sorting.');
            }

            $result = self::compareValues(self::value($a, $rule->getField()), self::value($b, $rule->getField()));

            if ($rule->getDirection() === FieldSorting::DESCENDING) {
                $result = -$result;
            }

            if ($result !== 0) {
                return $result;
            }
        }

        return 0;
    }

    /**
     * MySQL sorts NULL as the lowest possible value: first in ASCENDING, last
     * in DESCENDING. This always compares as ascending-with-nulls-first; the
     * caller negates the result for DESCENDING, which correctly moves nulls
     * to the other end too rather than just reversing non-null pairs.
     */
    private static function compareValues(mixed $a, mixed $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        return $a <=> $b;
    }

    /**
     * A getter first, then a public property -- covers both entities this
     * test doubles: RuleEntity exposes everything the resolver sorts or
     * filters on (`id`, `priority`) only through getters, while
     * StrategyAssignment's own columns are plain public properties and only
     * `createdAt` (inherited from the base Entity) has one.
     */
    private static function value(object $row, string $field): mixed
    {
        $getter = 'get' . ucfirst($field);

        if (method_exists($row, $getter)) {
            return $row->$getter();
        }

        if (property_exists($row, $field)) {
            return $row->$field;
        }

        throw new \LogicException('CriteriaFilter does not know field "' . $field . '" on ' . $row::class . '.');
    }
}
