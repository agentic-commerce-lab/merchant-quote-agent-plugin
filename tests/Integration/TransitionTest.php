<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineException;

/**
 * The most invasive thing the bridge does. Everything here runs inside
 * DatabaseTransactionBehaviour's rolled-back transaction, which matters more
 * than for the other write tests because a transition has effects beyond the
 * `quote` row: StateMachineRegistry dispatches state-change events, and this
 * shop has active flows carrying `action.mail.send` on entering both
 * `in_review` and `replied`.
 *
 * Two independently verified layers keep that from reaching a real customer:
 * `SendEmailMessage` is routed to the `async` transport, whose DSN is
 * `doctrine://default`, so the send is an INSERT into `messenger_messages` that
 * the rollback discards; and MAILER_DSN points at `smtp://127.0.0.1:1025`,
 * which is mailcatcher running inside the container — a catcher that never
 * relays onward.
 *
 * Each test picks a quote whose current state actually offers the action, so
 * none of them can silently skip.
 */
final class TransitionTest extends IntegrationTestCase
{
    /**
     * `open --process--> in_review` per this shop's `quote.state` machine. The
     * target state is asserted exactly rather than as "something changed": a
     * transition that landed somewhere else would be a state machine
     * misconfiguration worth failing on.
     */
    public function testProcessMovesAnOpenQuoteIntoReview(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'open');

        self::assertSame('open', $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName);

        $gateway->transition($quoteId, QuoteTransition::Process);

        self::assertSame(
            'in_review',
            $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName,
            'process did not move the quote from open to in_review.',
        );
    }

    /**
     * SPIKE FINDING (expiration ordering) — RESULT, first half.
     *
     * Setting the expiration before `sent` works and the date survives the
     * transition unchanged, and the resulting `replied` quote is outside the
     * expire task's reach. The second half of the finding is the test below.
     */
    public function testExpirationSetBeforeSentSurvivesTheTransition(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'in_review');
        $expires = new \DateTimeImmutable('+14 days');

        $gateway->updateQuote($quoteId, new QuoteUpdate(expiresAt: $expires));
        $gateway->transition($quoteId, QuoteTransition::Sent);

        $after = $gateway->fetchSnapshot($quoteId);
        self::assertSame(
            'replied',
            $after->lifecycle->stateTechnicalName,
            'sent did not move the quote from in_review to replied.',
        );
        self::assertNotNull($after->lifecycle->expiresAt, 'The expiration was cleared by the transition.');
        self::assertSame(
            $expires->format('Y-m-d H:i'),
            $after->lifecycle->expiresAt->format('Y-m-d H:i'),
            'The transition moved the expiration we set.',
        );
        self::assertFalse(
            $this->isEligibleForTheExpireTask($quoteId),
            'A quote sent with a future expiration is already eligible to be auto-expired.',
        );
    }

    /**
     * SPIKE FINDING (expiration ordering) — RESULT, second half: the TS
     * implementation's rule STILL HOLDS, and this is the mechanism.
     *
     * The `sent` transition itself never expires anything — the quote below
     * reaches `replied` with an expiration three weeks in the past and stays
     * `replied`. What expires it is `UpdateQuoteExpireTaskHandler::run()`, out
     * of process, which transitions every quote matching
     * `state = replied AND expiration_date <= now`. Worse,
     * `QuoteExpirationDateTimeSubscriber` reschedules that task to the earliest
     * pending replied expiration on every quote write carrying an
     * `expirationDate`, so the window is not bounded by the task's 86400s
     * interval.
     *
     * So the ordering requirement survives, but for a different reason than
     * assumed: it is not an Admin-API artefact and it is not the transition. It
     * is that `replied` plus a stale date is an auto-expire trigger, and
     * setting the expiration first means the quote is never in that state. A
     * NULL expiration is also safe (`NULL <= now` is not true), so only a stale
     * date is dangerous.
     *
     * The stale date is written explicitly rather than relying on a fixture
     * quote that happens to carry one.
     */
    public function testSentWithAStaleExpirationLeavesTheQuoteEligibleForTheExpireTask(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'in_review');

        $gateway->updateQuote($quoteId, new QuoteUpdate(expiresAt: new \DateTimeImmutable('-3 weeks')));
        $gateway->transition($quoteId, QuoteTransition::Sent);

        self::assertSame(
            'replied',
            $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName,
            'The sent transition expired the quote by itself, which would change the finding above.',
        );
        self::assertTrue(
            $this->isEligibleForTheExpireTask($quoteId),
            'A replied quote with a past expiration is NOT matched by the expire task query, so the '
            . 'set-expiration-before-sent ordering is no longer required and this finding is stale.',
        );
    }

    /**
     * The state machine, not the bridge, is what validates an action — so this
     * is the proof that `transition()` really goes through it. A quote in
     * `in_review` is offered `sent` and `admin_cancel`, never `request_change`.
     */
    public function testAnActionTheCurrentStateDoesNotOfferIsRejectedWithoutChangingTheState(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::quoteIdInState(static::getContainer(), Context::createDefaultContext(), 'in_review');

        $caught = null;
        try {
            $gateway->transition($quoteId, QuoteTransition::RequestChange);
        } catch (IllegalTransitionException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'An illegal transition was silently accepted.');
        self::assertSame(
            'in_review',
            $gateway->fetchSnapshot($quoteId)->lifecycle->stateTechnicalName,
            'The rejected transition still moved the quote.',
        );
    }

    /**
     * Pins what the interface documents: an unknown id is Shopware's own
     * StateMachineException ("Unable to read entity quote with id …"), not the
     * bridge's QuoteNotFoundException. Asserted as the exact class rather than
     * with instanceof, because IllegalTransitionException extends it and that
     * is a different failure entirely.
     */
    public function testTransitioningAnUnknownQuoteRaisesTheStateMachinesOwnError(): void
    {
        $caught = null;
        try {
            static::gateway()->transition(Uuid::randomHex(), QuoteTransition::Sent);
        } catch (StateMachineException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Transitioning a quote that does not exist was silently accepted.');
        self::assertSame(StateMachineException::class, $caught::class, $caught->getMessage());
    }

    /**
     * The three conditions `UpdateQuoteExpireTaskHandler::run()` selects on —
     * live lane, `state = replied`, `expiration_date <= now` — expressed
     * through the DAL rather than its raw SQL, so this suite stays free of a
     * doctrine/dbal import the plugin does not declare. The default context
     * supplies the live-version condition. `replied` mirrors
     * QuoteStates::STATE_REPLIED, held as a literal because SwagCommercial is a
     * runtime-only dependency.
     */
    private function isEligibleForTheExpireTask(string $quoteId): bool
    {
        /** @var EntityRepository<covariant EntityCollection> $quotes */
        $quotes = static::getContainer()->get('quote.repository');

        $criteria = new Criteria([$quoteId]);
        $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', 'replied'));
        $criteria->addFilter(new RangeFilter('expirationDate', [
            RangeFilter::LTE => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]));

        return $quotes->searchIds($criteria, Context::createDefaultContext())->firstId() !== null;
    }
}
