<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * `user_id` on `state_machine_history` is written only for an AdminApiSource
 * (core's StateMachineRegistry::transition()), which is what makes it readable
 * as "a human merchant moved this quote". The agent's own transitions carry a
 * SystemSource and the buyer's carry a SalesChannelApiSource; both write null.
 */
final class MerchantActionReaderTest extends IntegrationTestCase
{
    /**
     * The shape a SystemSource (the agent) or a SalesChannelApiSource (the
     * buyer) writes: neither a user nor an integration. `anyQuoteId()` picks
     * the same fixture quote deterministically every run, and this asserts
     * the precondition it relies on rather than assuming it, so a quote that
     * already carries real admin history fails here with a clear reason
     * instead of masking a broken exclusion filter below.
     */
    public function testAnAgentDrivenTransitionIsNotReadAsAHumansAction(): void
    {
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        self::assertNull(
            $reader->lastTransition($quoteId, $context)[0] ?? null,
            'Precondition: this fixture quote must carry no admin-authored history before this test writes one.',
        );

        $this->writeHistoryRow($quoteId, $context, userId: null, integrationId: null);

        self::assertNull(
            $reader->lastTransition($quoteId, $context)[0] ?? null,
            'A row with neither a user nor an integration is not a human transition.',
        );
    }

    public function testAHistoryRowCarryingAUserIsReadAsAHumansAction(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        // Two rows, not one: every other test in this file writes exactly one,
        // so FieldSorting::DESCENDING is never exercised there — inverted to
        // ASCENDING, the reader would return the OLDEST admin transition
        // instead and still pass a single-row test. Only the newer timestamp
        // must come back.
        $older = new \DateTimeImmutable('-2 hours');
        $newer = new \DateTimeImmutable('-1 hour');
        $userId = static::anyAdminUserId($context);
        $this->writeHistoryRow($quoteId, $context, userId: $userId, integrationId: null, createdAt: $older);
        $this->writeHistoryRow($quoteId, $context, userId: $userId, integrationId: null, createdAt: $newer);

        $found = $reader->lastTransition($quoteId, $context)[0] ?? null;
        self::assertNotNull($found, 'A history row carrying a user id is a human merchant acting.');
        self::assertSame(
            $newer->format('Y-m-d H:i:s'),
            $found->format('Y-m-d H:i:s'),
            'Newest admin transition must win; an inverted sort would silently return the oldest one.',
        );
        self::assertSame(
            'open',
            $reader->lastTransition($quoteId, $context)[1] ?? null,
            'The target state is what tells a sent answer (`replied`) from a merchant still working.',
        );
    }

    /**
     * Every other test in this file fabricates the history row directly
     * through the repository, in exactly the shape MerchantActionReader
     * filters on (`entityName` => 'quote', `referencedId` => the quote id).
     * That is an assumption about what core writes, not a fact this suite
     * has verified anywhere — the spec asked for a real admin transition, and
     * this is it: it drives `StateMachineRegistry::transition()` itself, with
     * an `AdminApiSource` context, and only THEN asks the reader. If core
     * wrote a different entityName or put the quote id somewhere else, this
     * is the test that fails — every other test here would keep passing
     * regardless, because they all bake the same assumption into their own
     * fixture row.
     */
    public function testARealAdminTransitionIsFoundByTheReader(): void
    {
        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        self::assertNull(
            $reader->lastTransition($quoteId, $context)[0] ?? null,
            'Precondition: this fixture quote must carry no admin-authored history before this test writes one.',
        );

        $registry = static::getContainer()->get(StateMachineRegistry::class);
        self::assertInstanceOf(StateMachineRegistry::class, $registry);

        // Read what the quote's CURRENT state actually offers rather than
        // hardcoding a transition name, so this does not break on a quote
        // that happens to sit in an unexpected state.
        $transitions = $registry->getAvailableTransitions('quote', $quoteId, 'stateId', $context);
        self::assertNotEmpty($transitions, 'The fixture quote\'s current state offers no transition at all.');
        $actionName = $transitions[0]->getActionName();

        $adminContext = new Context(new AdminApiSource(static::anyAdminUserId($context)));
        $registry->transition(new Transition('quote', $quoteId, $actionName, 'stateId'), $adminContext);

        self::assertNotNull(
            $reader->lastTransition($quoteId, $context)[0] ?? null,
            'A real admin-sourced transition was not found by the reader — check the entityName/referencedId/'
            . 'userId assumption MerchantActionReader\'s criteria relies on against what core actually wrote.',
        );
    }

    /**
     * An integration acting through the admin API sets `integration_id` and
     * leaves `user_id` null (core's IntegrationDefinition/AdminApiSource), so
     * an ERP sync is not read as a person. Same precondition reasoning as
     * the SystemSource test above.
     */
    public function testAnIntegrationsTransitionIsNotAPerson(): void
    {
        $reader = static::getContainer()->get(MerchantActionReader::class);
        self::assertInstanceOf(MerchantActionReader::class, $reader);

        $context = Context::createDefaultContext();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), $context);

        self::assertNull(
            $reader->lastTransition($quoteId, $context)[0] ?? null,
            'Precondition: this fixture quote must carry no admin-authored history before this test writes one.',
        );

        $this->writeHistoryRow($quoteId, $context, userId: null, integrationId: static::anyIntegrationId($context));

        self::assertNull(
            $reader->lastTransition($quoteId, $context)[0] ?? null,
            'An integration acting through the admin API is not a person.',
        );
    }

    /** Shared by every test above; each writes one history row in a different authorship shape. */
    private function writeHistoryRow(
        string $quoteId,
        Context $context,
        ?string $userId,
        ?string $integrationId,
        ?\DateTimeImmutable $createdAt = null,
    ): void {
        $history = static::getContainer()->get('state_machine_history.repository');
        self::assertNotNull($history);

        $stateId = static::quoteStateId($context);
        $row = [
            'id' => Uuid::randomHex(),
            'stateMachineId' => static::quoteStateMachineId($context),
            'entityName' => 'quote',
            'referencedId' => $quoteId,
            'referencedVersionId' => $context->getVersionId(),
            'fromStateId' => $stateId,
            'toStateId' => $stateId,
            'transitionActionName' => 'test_transition',
            'userId' => $userId,
            'integrationId' => $integrationId,
        ];

        if ($createdAt !== null) {
            $row['createdAt'] = $createdAt;
        }

        $history->create([$row], $context);
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

    /**
     * Unlike the other ids above, a shop under test may genuinely have zero
     * integrations, so this creates one rather than skipping the test.
     * `DatabaseTransactionBehaviour` rolls it back with everything else this
     * test writes.
     */
    private static function anyIntegrationId(Context $context): string
    {
        $repository = static::getContainer()->get('integration.repository');
        self::assertNotNull($repository);

        $id = $repository->searchIds(new Criteria(), $context)->firstId();
        if (\is_string($id)) {
            return $id;
        }

        $id = Uuid::randomHex();
        $repository->create([[
            'id' => $id,
            'label' => 'merchant-action-reader-test',
            'accessKey' => Uuid::randomHex(),
            'secretAccessKey' => Uuid::randomHex(),
        ]], $context);

        return $id;
    }
}
