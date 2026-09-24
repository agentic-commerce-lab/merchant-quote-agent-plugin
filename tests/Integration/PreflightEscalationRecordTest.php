<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingLock;
use MerchantQuoteAgentPlugin\Servicing\QuoteServicingPipelineInterface;
use MerchantQuoteAgentPlugin\Servicing\ServiceQuoteHandler;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\ServicingTestJournal;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * #35 against the real container: a misconfigured channel's preflight
 * refusal now writes its own row in `merchant_quote_agent_decision`, read
 * back exactly the way #7's admin pages and #21's run read it.
 *
 * A separate file rather than an extension of ServicingConfigGateTest, on
 * purpose. That class's testAMissingApiKeyEscalatesOnceRatherThanDecidingQuietly
 * is issue #140 -- known-failing on this shop today, and possibly being
 * rewritten by a parallel agent. Building on it here would entangle two
 * changes that have nothing to do with each other. Accordingly: nothing in
 * this file asserts how many buyer comments were written. The row count is
 * this work's claim; the comment count is #140's.
 */
final class PreflightEscalationRecordTest extends IntegrationTestCase
{
    public function testAMisconfiguredChannelWritesAnEscalatedRowNamingTheFieldsAtFault(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $config = self::config();
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');

        self::handler($gateway)(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten));

        $ids = self::decisionIds($quoteId);
        self::assertCount(1, $ids, 'A misconfigured channel must write exactly one row for the refused pass.');

        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);
        $row = $repository->search(new Criteria($ids), Context::createDefaultContext())->first();

        self::assertNotNull($row);
        self::assertSame($quoteId, $row->quoteId);
        self::assertSame('escalated', $row->outcome);
        self::assertSame('not_configured', $row->escalationReason);
        self::assertSame('comment_written', $row->triggerReason);
        // What #7's not_configured snippet has been promising to a row that
        // never existed: "The technical details below name the fields."
        self::assertNotEmpty($row->violations);
        // Nothing ran, so nothing here may claim it did.
        self::assertNull($row->durationMs);
        self::assertNull($row->band);
        self::assertNull($row->model);
    }

    public function testEachRefusedPassIsItsOwnRow(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        $config = self::config();
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', true);
        $config->set(QuoteAgentSettingsReader::DOMAIN . 'llmApiKey', '');

        $message = ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten);
        $handler = self::handler($gateway);
        $handler($message);
        $handler($message);

        // The marker makes the buyer hear this once (#140); the table logs
        // passes, not marker transitions, so two refused triggers are two
        // rows. The comment count is #140's business and is deliberately not
        // asserted here.
        self::assertCount(2, self::decisionIds($quoteId));
    }

    public function testADisabledSalesChannelWritesNoDecisionRow(): void
    {
        $gateway = static::gateway();
        $quoteId = QuoteFixture::anyQuoteId(static::getContainer(), Context::createDefaultContext());
        self::config()->set(QuoteAgentSettingsReader::DOMAIN . 'enabled', false);

        self::handler($gateway)(ServiceQuoteMessage::because($quoteId, ServicingTriggerReason::CommentWritten));

        // A paused agent is not an agent action, so it is not an audit event.
        self::assertSame([], self::decisionIds($quoteId));
    }

    /** @return list<string> the decision-row ids this quote has, oldest first */
    private static function decisionIds(string $quoteId): array
    {
        $repository = static::getContainer()->get('merchant_quote_agent_decision.repository');
        self::assertInstanceOf(EntityRepository::class, $repository);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('quoteId', $quoteId));
        $criteria->addSorting(new FieldSorting('createdAt'));

        return array_values($repository->searchIds($criteria, Context::createDefaultContext())->getIds());
    }

    private static function config(): SystemConfigService
    {
        $config = static::getContainer()->get(SystemConfigService::class);
        self::assertInstanceOf(SystemConfigService::class, $config);

        return $config;
    }

    /** @return QuoteServicingPipelineInterface&object{passes: int} */
    private static function countingPipeline(): object
    {
        return new class implements QuoteServicingPipelineInterface {
            public int $passes = 0;

            #[\Override]
            public function service(
                QuoteSnapshot $snapshot,
                QuoteGatewayInterface $gateway,
                QuoteAgentSettings $settings,
                PassContext $context,
            ): NegotiationOutcome {
                ++$this->passes;

                return NegotiationOutcome::Offered;
            }
        };
    }

    private static function handler(QuoteGatewayInterface $gateway): ServiceQuoteHandler
    {
        return new ServiceQuoteHandler(
            new QuoteServicingLock(new LockFactory(new InMemoryStore()), 'redis://x'),
            ServicingTestJournal::create(),
            static::preflight(),
            $gateway,
            self::countingPipeline(),
        );
    }
}
