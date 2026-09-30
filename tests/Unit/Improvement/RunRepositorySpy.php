<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Improvement\ImprovementRun;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;

/**
 * A two-way test double over `merchant_quote_agent_improvement_run.repository`:
 * `search()` answers with whatever "last completed run" the test primed it
 * with, and every `create()`/`update()` payload lands in `written` /
 * `updated` for the generator tests to assert on.
 *
 * A genuine EntityRepository subclass rather than a PHPUnit mock:
 * MockBuilder::createMock() is protected on TestCase, so a shared, reusable
 * double cannot build one from outside a test method. Overriding the three
 * methods actually called and never invoking the parent constructor is safe
 * here for the same reason PHPUnit's own generated doubles are -- nothing
 * below reads the parent's (unset) collaborators.
 */
final class RunRepositorySpy extends EntityRepository
{
    /** @var list<array<string, mixed>> */
    public array $written = [];

    /** @var list<array<string, mixed>> */
    public array $updated = [];

    /**
     * Settable AFTER construction, deliberately: a test builds the spy first
     * and only decides the window (and so the run it wants search() to answer
     * with) once it calls generatorFor().
     */
    public ?ImprovementRun $lastCompleted;

    public function __construct(?ImprovementRun $lastCompleted = null)
    {
        $this->lastCompleted = $lastCompleted;
    }

    #[\Override]
    public function search(Criteria $criteria, Context $context): EntitySearchResult
    {
        $rows = $this->lastCompleted === null ? [] : [$this->lastCompleted];

        return new EntitySearchResult(
            'merchant_quote_agent_improvement_run',
            \count($rows),
            new EntityCollection($rows),
            null,
            $criteria,
            $context,
        );
    }

    #[\Override]
    public function create(array $data, Context $context): EntityWrittenContainerEvent
    {
        $this->written[] = $data[0];

        return self::event($context);
    }

    #[\Override]
    public function update(array $data, Context $context): EntityWrittenContainerEvent
    {
        $this->updated[] = $data[0];

        return self::event($context);
    }

    private static function event(Context $context): EntityWrittenContainerEvent
    {
        return new EntityWrittenContainerEvent($context, new NestedEventCollection([]), []);
    }
}
