<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Bridge\NotADraftVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;

/**
 * A gateway bound to the live version drafts onto the buyer-visible quote, a
 * delete in its context deletes the live quote, and a merge of the snapshot
 * lane replays SwagCommercial's "last sent" copy over it — so all three refuse
 * anything but a draft id before a collaborator is ever reached.
 */
final class QuoteDraftVersionsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function notADraft(): iterable
    {
        yield 'the live version' => [Defaults::LIVE_VERSION];
        yield 'the snapshot lane' => [QuoteVersionResolver::SNAPSHOT_VERSION_ID];
        yield 'not a uuid' => ['draft-1'];
    }

    #[DataProvider('notADraft')]
    public function testDeleteRefusesAnythingButADraft(string $versionId): void
    {
        $this->expectException(NotADraftVersion::class);

        $this->untouchable()->delete('0190aaaa0000700080000000000000bb', $versionId);
    }

    #[DataProvider('notADraft')]
    public function testMergeRefusesAnythingButADraft(string $versionId): void
    {
        $this->expectException(NotADraftVersion::class);

        $this->untouchable()->merge($versionId);
    }

    #[DataProvider('notADraft')]
    public function testGatewayRefusesAnythingButADraft(string $versionId): void
    {
        $this->expectException(NotADraftVersion::class);

        $this->untouchable()->gateway($versionId);
    }

    public function testExistsLooksTheVersionUpById(): void
    {
        $versions = $this->withVersionRows(['0190aaaa0000700080000000000000aa']);

        self::assertTrue($versions->exists('0190aaaa0000700080000000000000aa'));
        self::assertFalse($versions->exists('0190cccc0000700080000000000000cc'));
    }

    public function testANonUuidDoesNotExistAndIsNeverSearched(): void
    {
        self::assertFalse($this->untouchable()->exists('draft-1'));
    }

    /**
     * A version repository holding exactly these ids.
     *
     * @param list<string> $ids
     */
    private function withVersionRows(array $ids): QuoteDraftVersions
    {
        $versions = $this->createMock(EntityRepository::class);
        $versions
            ->method('searchIds')
            ->willReturnCallback(static function (Criteria $criteria, Context $context) use ($ids): IdSearchResult {
                $found = [];

                foreach (array_intersect($ids, $criteria->getIds()) as $id) {
                    $found[$id] = ['primaryKey' => $id, 'data' => []];
                }

                return new IdSearchResult(\count($found), $found, $criteria, $context);
            });

        return new QuoteDraftVersions(
            $this->createMock(EntityRepository::class),
            $versions,
            $this->createMock(ContextBoundGateways::class),
        );
    }

    /** Every collaborator fails the test if it is reached at all. */
    private function untouchable(): QuoteDraftVersions
    {
        $quotes = $this->createMock(EntityRepository::class);
        $quotes->expects(self::never())->method(self::anything());
        $versions = $this->createMock(EntityRepository::class);
        $versions->expects(self::never())->method(self::anything());
        $gateways = $this->createMock(ContextBoundGateways::class);
        $gateways->expects(self::never())->method(self::anything());

        return new QuoteDraftVersions($quotes, $versions, $gateways);
    }
}
