<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Improvement;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\MerchantActionReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteSnapshotReader;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use MerchantQuoteAgentPlugin\Improvement\DecisionClassification;
use MerchantQuoteAgentPlugin\Improvement\DecisionDiscount;
use MerchantQuoteAgentPlugin\Improvement\DecisionExtraction;
use MerchantQuoteAgentPlugin\Improvement\HarvestedDecision;
use MerchantQuoteAgentPlugin\Improvement\ReplaySubjectResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyResolver;
use MerchantQuoteAgentPlugin\Strategy\StrategyVersion;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The strategy check this class runs BEFORE any DAL read of the quote (see
 * its own docblock): it needs only the decision itself, so both of these
 * cases are provable without a working QuoteSnapshotReader fixture -- which
 * this codebase deliberately has none of at the unit level (QuoteSnapshotReader
 * is exercised only by the integration suite against a real shop; see its own
 * class docblock). A real quote read is never reached in either test here.
 */
final class ReplaySubjectResolverTest extends TestCase
{
    public function testItSkipsWithoutCallingTheStrategyResolverWhenNoVersionIsRecorded(): void
    {
        $strategies = $this->createMock(StrategyResolver::class);
        $strategies->expects(self::never())->method('byVersionId');

        $resolver = new ReplaySubjectResolver($this->emptyQuotes(), $strategies);

        $subject = $resolver->resolve($this->decision(null), Context::createDefaultContext());

        self::assertNull($subject);
    }

    public function testItSkipsWhenTheRecordedVersionCannotBeRead(): void
    {
        $strategies = $this->createMock(StrategyResolver::class);
        $strategies
            ->expects(self::once())
            ->method('byVersionId')
            ->with('version-1', self::isInstanceOf(Context::class))
            ->willReturn(null);

        $resolver = new ReplaySubjectResolver($this->emptyQuotes(), $strategies);

        $subject = $resolver->resolve($this->decision('version-1'), Context::createDefaultContext());

        self::assertNull($subject);
    }

    /**
     * A resolvable version still ends in a skip here, because the fixture's
     * QuoteSnapshotReader repository is empty and read() always throws
     * QuoteNotFoundException -- that is the point: it proves the strategy
     * check happening first does not shortcut the quote read for a decision
     * whose strategy DOES resolve.
     */
    public function testAResolvableStrategyStillSkipsOnAnUnreadableQuote(): void
    {
        $version = new StrategyVersion();
        $version->setUniqueIdentifier('version-1');
        $version->id = 'version-1';
        $version->prompt = 'hold firm';

        $strategies = $this->createMock(StrategyResolver::class);
        $strategies->method('byVersionId')->willReturn($version);

        $resolver = new ReplaySubjectResolver($this->emptyQuotes(), $strategies);

        self::assertNull($resolver->resolve($this->decision('version-1'), Context::createDefaultContext()));
    }

    private function decision(?string $strategyVersionId): HarvestedDecision
    {
        return new HarvestedDecision(
            Uuid::randomHex(),
            Uuid::randomHex(),
            new DecisionClassification('grant', 'offered', null, null),
            new DecisionDiscount(5.0, 10.0),
            new DecisionExtraction(null, null, $strategyVersionId, 'config'),
        );
    }

    private function emptyQuotes(): QuoteSnapshotReader
    {
        return new QuoteSnapshotReader(
            $this->emptyRepository(),
            new QuoteVersionResolver(),
            CommercialCapabilities::modern(),
            new MerchantActionReader($this->emptyRepository()),
        );
    }

    private function emptyRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(
                static fn(Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                    'test',
                    0,
                    new EntityCollection([]),
                    null,
                    $criteria,
                    $context,
                ),
            );

        return $repository;
    }
}
