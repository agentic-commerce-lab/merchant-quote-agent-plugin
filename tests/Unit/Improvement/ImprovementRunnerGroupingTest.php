<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteSnapshotReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use MerchantQuoteAgentPlugin\Config\ModelAccess;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Improvement\DecisionHarvest;
use MerchantQuoteAgentPlugin\Improvement\ImprovementCadence;
use MerchantQuoteAgentPlugin\Improvement\ImprovementGenerator;
use MerchantQuoteAgentPlugin\Improvement\ImprovementJudge;
use MerchantQuoteAgentPlugin\Improvement\ImprovementRunner;
use MerchantQuoteAgentPlugin\Improvement\ImprovementRunWriter;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettings;
use MerchantQuoteAgentPlugin\Improvement\ImprovementSettingsReader;
use MerchantQuoteAgentPlugin\Improvement\JudgeAnswer;
use MerchantQuoteAgentPlugin\Improvement\JudgeCandidate;
use MerchantQuoteAgentPlugin\Improvement\ReplayEvaluator;
use MerchantQuoteAgentPlugin\Improvement\ReplayHarness;
use MerchantQuoteAgentPlugin\Improvement\ReplaySubjectResolver;
use MerchantQuoteAgentPlugin\Improvement\RunSettingsResolver;
use MerchantQuoteAgentPlugin\Improvement\RunStatus;
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
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The two behaviours the per-strategy design brief names as its minimum bar,
 * proven together with one scripted judge: a window holding decisions from
 * two strategies produces two run rows (not one, and not the channel
 * default's alone), and each group's judge call carries THAT group's own
 * current prompt, which is what makes each candidate land on the right
 * lineage when StrategyProposalWriter appends it.
 *
 * The replayed sample is always empty here (QuoteSnapshotReader's repository
 * is empty, so every decision is skipped before the replay proper -- see
 * ReplaySubjectResolverTest for why that boundary is never faked at the unit
 * level in this codebase). That does not weaken what this test proves:
 * candidates come from the judge alone, independent of sample size, so
 * `sampled = 0` here is orthogonal to "did this group get its own prompt and
 * its own proposal".
 */
final class ImprovementRunnerGroupingTest extends TestCase
{
    private const STRATEGY_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const VERSION_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';

    private const STRATEGY_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const VERSION_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb1';

    public function testTwoStrategiesProduceTwoRunsEachProposingOnItsOwnLineage(): void
    {
        $runs = new RunRepositorySpy();
        $proposedVersions = new VersionRepositorySpy([
            self::version(self::VERSION_A, self::STRATEGY_A, 'prompt for strategy A'),
            self::version(self::VERSION_B, self::STRATEGY_B, 'prompt for strategy B'),
        ]);
        $strategies = new StrategyResolver($this->strategiesRepository(), $proposedVersions);

        $recorder = new DecisionRecorder(new TallyingDecisionWriter());
        $reply = static function (string $userPrompt): string {
            $forA = str_contains($userPrompt, 'prompt for strategy A');

            return json_encode(
                new JudgeAnswer([], [new JudgeCandidate($forA ? 'candidate for A' : 'candidate for B', 'because')]),
                JSON_THROW_ON_ERROR,
            );
        };
        [$platform] = ScriptedClient::spy([$reply, $reply], $recorder);

        $generator = $this->generator($strategies, $recorder, $platform, $runs, $proposedVersions);

        $generator->generate(new \DateTimeImmutable('2026-09-21 03:00:00'));

        self::assertCount(2, $runs->written, 'one run row per strategy in the window');
        self::assertSame(RunStatus::Completed->value, $runs->updated[0]['status']);
        self::assertSame(RunStatus::Completed->value, $runs->updated[1]['status']);

        $strategyIds = array_map(static fn(array $row): mixed => $row['strategyId'] ?? null, $runs->written);
        sort($strategyIds);
        self::assertSame([self::STRATEGY_A, self::STRATEGY_B], $strategyIds);

        self::assertCount(2, $proposedVersions->created, 'one candidate proposal per strategy');

        $byStrategy = [];

        foreach ($proposedVersions->created as $row) {
            $byStrategy[$row['strategyId']] = $row;
        }

        self::assertSame('candidate for A', $byStrategy[self::STRATEGY_A]['prompt']);
        self::assertSame('candidate for B', $byStrategy[self::STRATEGY_B]['prompt']);
    }

    private function generator(
        StrategyResolver $strategies,
        DecisionRecorder $recorder,
        ModelPlatform $platform,
        RunRepositorySpy $runs,
        VersionRepositorySpy $proposedVersions,
    ): ImprovementGenerator {
        $tally = new TallyingDecisionWriter();
        $harvest = new DecisionHarvest($this->decisionsRepository(), $strategies);
        $judge = new ImprovementJudge($platform, $recorder);
        $harness = $this->harness($platform, $tally, $recorder, $strategies);
        $writer = new ImprovementRunWriter($runs, new StrategyProposalWriter($proposedVersions));

        $runner = new ImprovementRunner($this->settingsResolver(), $harvest, $judge, $harness, $writer);

        return new ImprovementGenerator($this->readOnlyRepository([self::salesChannel()]), $runner);
    }

    private function harness(
        ModelPlatform $platform,
        TallyingDecisionWriter $tally,
        DecisionRecorder $recorder,
        StrategyResolver $strategies,
    ): ReplayHarness {
        $quotes = new QuoteSnapshotReader(
            $this->readOnlyRepository([]),
            new QuoteVersionResolver(),
            CommercialCapabilities::modern(),
            new MerchantActionReader($this->readOnlyRepository([])),
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

    private function settingsResolver(): RunSettingsResolver
    {
        $settings = new ImprovementSettings(
            enabled: true,
            cadence: ImprovementCadence::Daily,
            sampleSize: 20,
            candidates: 1,
            llm: new ModelAccess('sk-improve', 'https://api.example.com/v1', 'gpt-4o-mini'),
        );

        $config = new class($settings) extends SystemConfigService {
            public function __construct(
                private readonly ImprovementSettings $settings,
            ) {}

            #[\Override]
            public function get(string $key, ?string $salesChannelId = null): mixed
            {
                $name = str_replace(ImprovementSettingsReader::DOMAIN, '', $key);

                return match ($name) {
                    'improvementEnabled' => true,
                    'improvementCadence' => $this->settings->cadence->value,
                    'improvementSampleSize' => $this->settings->sampleSize,
                    'improvementCandidates' => $this->settings->candidates,
                    'improvementLlmModel' => $this->settings->llm->model,
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

    private static function version(string $id, string $strategyId, string $prompt): StrategyVersion
    {
        $version = new StrategyVersion();
        $version->setUniqueIdentifier($id);
        $version->id = $id;
        $version->strategyId = $strategyId;
        $version->version = 1;
        $version->prompt = $prompt;
        $version->status = VersionStatus::Active->value;

        return $version;
    }

    private function strategiesRepository(): EntityRepository
    {
        $strategyA = new Strategy();
        $strategyA->setUniqueIdentifier(self::STRATEGY_A);
        $strategyA->id = self::STRATEGY_A;
        $strategyA->name = 'Strategy A';

        $strategyB = new Strategy();
        $strategyB->setUniqueIdentifier(self::STRATEGY_B);
        $strategyB->id = self::STRATEGY_B;
        $strategyB->name = 'Strategy B';

        $strategies = [$strategyA, $strategyB];
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($strategies): EntitySearchResult {
                $ids = $criteria->getIds();
                $matched = $ids === []
                    ? $strategies
                    : array_values(array_filter($strategies, static fn(Strategy $s): bool => \in_array(
                        $s->getUniqueIdentifier(),
                        $ids,
                        true,
                    )));

                return new EntitySearchResult(
                    'test',
                    \count($matched),
                    new EntityCollection($matched),
                    null,
                    $criteria,
                    $context,
                );
            });

        return $repository;
    }

    private function decisionsRepository(): EntityRepository
    {
        $decisions = [self::decision(self::VERSION_A), self::decision(self::VERSION_B)];
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(
                static fn(Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                    'merchant_quote_agent_decision',
                    \count($decisions),
                    new EntityCollection($decisions),
                    null,
                    $criteria,
                    $context,
                ),
            );

        return $repository;
    }

    private static function decision(string $strategyVersionId): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $id = Uuid::randomHex();
        $record->setUniqueIdentifier($id);
        $record->id = $id;
        $record->quoteId = Uuid::randomHex();
        $record->band = 'grant';
        $record->outcome = 'offered';
        $record->strategyVersionId = $strategyVersionId;

        return $record;
    }

    private static function salesChannel(): Entity
    {
        $channel = new Entity();
        $channel->setUniqueIdentifier('sc-1');

        return $channel;
    }

    /** @param list<object> $entities */
    private function readOnlyRepository(array $entities): EntityRepository
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
}
