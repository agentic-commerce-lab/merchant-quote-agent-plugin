<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The two invariants the strategy library's audit trail rests on:
 *
 * 1. A version row never changes and is never deleted. A decision stores a
 *    version id, so "what did we tell the model on that quote" must stay
 *    answerable however often the strategy is edited afterwards.
 * 2. A built-in strategy row never changes and is never deleted. Its text is
 *    what the merchant chose by name; silently editable built-ins would make
 *    the name meaningless.
 *
 * Enforced here rather than in the administration because the administration
 * is not in the path of an admin API token, and these entities are writable by
 * design -- unlike QuoteDecisionRecord, they carry no system-scope Protection,
 * because the merchant's own UI is what writes them.
 *
 * Built-in rows are refused ALL field updates rather than a protected subset.
 * One rule cannot drift as columns are added, and nothing legitimate writes
 * those rows after seeding: revising a built-in appends to the version table.
 */
final class StrategyWriteGuard implements EventSubscriberInterface
{
    /** @return array<string, string> */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [PreWriteValidationEvent::class => 'preValidate'];
    }

    public function preValidate(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if ($command instanceof InsertCommand) {
                continue;
            }

            $entity = $command->getEntityName();

            if ($entity === 'merchant_quote_agent_strategy_version') {
                $event->getExceptions()->add(ImmutableStrategy::version());

                continue;
            }

            if ($entity !== 'merchant_quote_agent_strategy') {
                continue;
            }

            $primaryKey = $command->getPrimaryKey()['id'] ?? null;

            if (\is_string($primaryKey) && BuiltInStrategies::isBuiltIn(bin2hex($primaryKey))) {
                $event->getExceptions()->add(ImmutableStrategy::builtIn());
            }
        }
    }
}
