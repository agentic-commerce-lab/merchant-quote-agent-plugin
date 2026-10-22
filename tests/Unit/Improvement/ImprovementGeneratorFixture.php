<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteSnapshotReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Improvement\DecisionHarvest;
use MerchantQuoteAgentPlugin\Improvement\ImprovementGenerator;
use MerchantQuoteAgentPlugin\Improvement\ImprovementJudge;
use MerchantQuoteAgentPlugin\Improvement\ImprovementRun;
use MerchantQuoteAgentPlugin\Improvement\ImprovementRunner;
use MerchantQuoteAgentPlugin\Improvement\ImprovementRunWriter;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettings;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettingsReader;
use MerchantQuoteAgentPlugin\Improvement\ReplayEvaluator;
use MerchantQuoteAgentPlugin\Improvement\ReplayHarness;
use MerchantQuoteAgentPlugin\Improvement\ReplaySubjectResolver;
use MerchantQuoteAgentPlugin\Improvement\RunSettingsResolver;
use MerchantQuoteAgentPlugin\Improvement\StrategyProposalWriter;
use MerchantQuoteAgentPlugin\Improvement\TallyingDecisionWriter;
use MerchantQuoteAgentPlugin\Negotiation\ModelPlatform;
use MerchantQuoteAgentPlugin\Negotiation\NoCustomerHistoryFactory;
use MerchantQuoteAgentPlugin\Negotiation\OfferProposer;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Policy\NegotiationDecider;
use MerchantQuoteAgentPlugin\Policy\OfferAuthorizer;
use MerchantQuoteAgentPlugin\Strategy\Strategy;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyVersion;
use MerchantQuoteAgentPlugin\Strategy\VersionStatus;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Builds a FULLY REAL ImprovementGenerator for ImprovementGeneratorTest: a
 * real ImprovementSettingsReader over a scripted SystemConfigService, a real
 * ReplayHarness (QuoteSnapshotReader, ReplayEvaluator, OfferProposer), all of
 * it inert and buildable without a kernel -- none of it is reachable by the
 * three behaviours that test asserts on, since each one short-circuits before
 * ImprovementRunner ever reaches the replay stage. Split out of the test
 * class to keep it under the method-count gate.
 *
 * Every read-only double below is a genuine EntityRepository/SystemConfigService
 * subclass (like RunRepositorySpy), never a PHPUnit mock: MockBuilder::createMock()
 * is protected on TestCase, so a shared, reusable double cannot build one
 * from outside a test method.
 */
final class ImprovementGeneratorFixture
{
    private const SALES_CHANNEL_ID = 'sc-1';

    /**
     * The one strategy/version pair every decision built by
     * ImprovementGeneratorTest's and ImprovementRunnerBillingTest's own
     * `decision()` helpers names, so DecisionHarvest's grouping (which now
     * runs before ANY of the three short-circuiting behaviours those tests
     * assert on) resolves every decision into exactly one group instead of
     * silently dropping it for want of an attributable strategy.
     */
    public const STRATEGY_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const STRATEGY_VERSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * $platformFactory builds the ModelPlatform AFTER this method already has
     * a DecisionRecorder in hand -- the SAME instance must back both
     * ImprovementJudge's begin()/finish() bracketing and the ModelPlatform's
     * own recordModelCall() calls (see ImprovementJudge's own docblock and
     * services.php's private wiring), or a real call's tokens silently never
     * reach the tally ReplayHarness::tokensSoFar() reads. Building the
     * platform first and handing it in, the way this method used to, cannot
     * express that: nothing outside this method can hand back the recorder it
     * is about to create.
     *
     * @param list<QuoteDecisionRecord>            $decisions
     * @param \Closure(DecisionRecorder): ModelPlatform $platformFactory
     */
    public function generator(
        ?ImprovementSettings $settings,
        ?\DateTimeImmutable $lastCompletedRun,
        array $decisions,
        \Closure $platformFactory,
        RunRepositorySpy $runs,
    ): ImprovementGenerator {
        if ($lastCompletedRun !== null) {
            $runs->lastCompleted = self::completedRun($lastCompletedRun);
        }

        $strategies = self::strategyResolver();
        $proposalWriter = new StrategyProposalWriter(self::readOnlyRepository([]));
        $tally = new TallyingDecisionWriter();
        $recorder = new DecisionRecorder($tally);
        $platform = $platformFactory($recorder);
        $runner = new ImprovementRunner(
            self::settingsResolver($settings),
            new DecisionHarvest(self::readOnlyRepository($decisions), $strategies),
            new ImprovementJudge($platform, $recorder),
            self::harness($platform, $tally, $recorder, $strategies),
            new ImprovementRunWriter($runs, $proposalWriter),
        );

        return new ImprovementGenerator(self::readOnlyRepository([self::salesChannel()]), $runner);
    }

    /**
     * One strategy, one active version -- STRATEGY_ID / STRATEGY_VERSION_ID
     * above -- wired the same way for both of StrategyResolver's reads
     * (resolve() and byVersionId()): readOnlyRepository ignores the Criteria
     * entirely and always hands back everything it was built with, so the
     * status/archived filters neither class applies are never actually
     * exercised here -- StrategyResolverTest is what pins those.
     */
    private static function strategyResolver(): StrategyResolver
    {
        $strategy = new Strategy();
        $strategy->setUniqueIdentifier(self::STRATEGY_ID);
        $strategy->id = self::STRATEGY_ID;
        $strategy->name = 'House style';

        $version = new StrategyVersion();
        $version->setUniqueIdentifier(self::STRATEGY_VERSION_ID);
        $version->id = self::STRATEGY_VERSION_ID;
        $version->strategyId = self::STRATEGY_ID;
        $version->version = 1;
        $version->prompt = 'hold firm';
        $version->status = VersionStatus::Active->value;

        return new StrategyResolver(self::readOnlyRepository([$strategy]), self::readOnlyRepository([$version]));
    }

    private static function completedRun(\DateTimeImmutable $finishedAt): ImprovementRun
    {
        $run = new ImprovementRun();
        $run->setUniqueIdentifier('run-1');
        $run->id = 'run-1';
        $run->finishedAt = $finishedAt;

        return $run;
    }

    private static function settingsResolver(?ImprovementSettings $settings): RunSettingsResolver
    {
        $config = new class($settings) extends SystemConfigService {
            public function __construct(
                private readonly ?ImprovementSettings $settings,
            ) {}

            #[\Override]
            public function get(string $key, ?string $salesChannelId = null): mixed
            {
                $name = str_replace(ImprovementSettingsReader::DOMAIN, '', $key);

                return match ($name) {
                    'improvementEnabled' => $this->settings !== null,
                    'improvementCadence' => $this->settings?->cadence->value,
                    'improvementSampleSize' => $this->settings?->sampleSize,
                    'improvementCandidates' => $this->settings?->candidates,
                    'improvementLlmModel' => $this->settings?->llm->model,
                    default => null,
                };
            }
        };

        $agent = new class implements QuoteAgentSettingsSource {
            #[\Override]
            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return NegotiationFixture::settings();
            }
        };

        return new RunSettingsResolver(new ImprovementSettingsReader($config, $agent), $agent);
    }

    private static function salesChannel(): Entity
    {
        $channel = new Entity();
        $channel->setUniqueIdentifier(self::SALES_CHANNEL_ID);

        return $channel;
    }

    /** @param list<object> $entities */
    private static function readOnlyRepository(array $entities): EntityRepository
    {
        return new class($entities) extends EntityRepository {
            /** @param list<object> $entities */
            public function __construct(
                private readonly array $entities,
            ) {}

            #[\Override]
            public function search(Criteria $criteria, Context $context): EntitySearchResult
            {
                return new EntitySearchResult(
                    'test',
                    \count($this->entities),
                    new EntityCollection($this->entities),
                    null,
                    $criteria,
                    $context,
                );
            }

            #[\Override]
            public function create(array $data, Context $context): EntityWrittenContainerEvent
            {
                return new EntityWrittenContainerEvent($context, new NestedEventCollection([]), []);
            }
        };
    }

    private static function harness(
        ModelPlatform $platform,
        TallyingDecisionWriter $tally,
        DecisionRecorder $recorder,
        StrategyResolver $strategies,
    ): ReplayHarness {
        $quotes = new QuoteSnapshotReader(
            self::readOnlyRepository([]),
            new QuoteVersionResolver(),
            CommercialCapabilities::modern(),
            new MerchantActionReader(self::readOnlyRepository([])),
        );
        $subjects = new ReplaySubjectResolver($quotes, $strategies);

        $proposer = new OfferProposer(
            $platform,
            new PromptComposer('EXTRACT', 'NEGOTIATE BASE', 'REPLY {{tone}}'),
            new OfferAuthorizer(),
            $recorder,
            new NoCustomerHistoryFactory(),
        );
        $evaluator = new ReplayEvaluator(new NegotiationDecider(), $proposer, $recorder);

        return new ReplayHarness($subjects, $evaluator, $tally);
    }
}
