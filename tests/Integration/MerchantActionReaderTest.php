<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * `user_id` on `state_machine_history` is written only for an AdminApiSource
 * (core's StateMachineRegistry::transition()), which is what makes it readable
 * as "a human merchant moved this quote". The agent's own transitions carry a
 * SystemSource and the buyer's carry a SalesChannelApiSource; both write null.
 */
final class MerchantActionReaderTest extends IntegrationTestCase
{
    public function testAnAgentDrivenTransitionIsNotReadAsAHumansAction(): void
    {
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $before = $reader->lastTransitionAt($quoteId, Context::createDefaultContext());

        self::assertNull(
            $reader->lastTransitionAt($quoteId, Context::createDefaultContext()),
            'A read must not invent an action; this quote has had no admin transition in this test.',
        );
        self::assertSame($before, $reader->lastTransitionAt($quoteId, Context::createDefaultContext()));
    }

    public function testAHistoryRowCarryingAUserIsReadAsAHumansAction(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        $history = static::getContainer()->get('state_machine_history.repository');
        self::assertNotNull($history);

        $stateId = static::quoteStateId($context);
        $userId = static::anyAdminUserId($context);
        $history->create([[
            'id' => Uuid::randomHex(),
            'stateMachineId' => static::quoteStateMachineId($context),
            'entityName' => 'quote',
            'referencedId' => $quoteId,
            'referencedVersionId' => $context->getVersionId(),
            'fromStateId' => $stateId,
            'toStateId' => $stateId,
            'transitionActionName' => 'test_transition',
            'userId' => $userId,
        ]], $context);

        self::assertNotNull(
            $reader->lastTransitionAt($quoteId, $context),
            'A history row carrying a user id is a human merchant acting.',
        );
    }

    /** An AdminApiSource with no user id is an integration, not a person. */
    public function testAnIntegrationsTransitionIsNotAPerson(): void
    {
        self::assertNull((new AdminApiSource(null, Uuid::randomHex()))->getUserId());
    }

    private static function quoteStateMachineId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', 'quote.state'));
        $id = static::getContainer()->get('state_machine.repository')?->searchIds($criteria, $context)->firstId();
        self::assertIsString($id, 'This shop has no quote.state state machine; SwagCommercial is not installed.');

        return $id;
    }

    private static function quoteStateId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachineId', self::quoteStateMachineId($context)));
        $criteria->addFilter(new EqualsFilter('technicalName', 'open'));
        $id = static::getContainer()->get('state_machine_state.repository')?->searchIds($criteria, $context)->firstId();
        self::assertIsString($id, 'The quote state machine has no `open` state.');

        return $id;
    }

    private static function anyAdminUserId(Context $context): string
    {
        $id = static::getContainer()->get('user.repository')?->searchIds(new Criteria(), $context)->firstId();
        self::assertIsString($id, 'This shop has no administration user to attribute a transition to.');

        return $id;
    }
}
