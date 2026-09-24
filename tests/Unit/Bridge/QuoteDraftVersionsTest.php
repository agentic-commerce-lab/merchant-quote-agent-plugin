<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use Doctrine\DBAL\Connection;
use MerchantQuoteAgentPlugin\Bridge\ContextBoundGateways;
use MerchantQuoteAgentPlugin\Bridge\NotADraftVersion;
use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersions;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

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

    public function testExistsRequiresTheVersionAndThisQuotesDraftRow(): void
    {
        $quoteId = '0190aaaa0000700080000000000000bb';
        $versions = $this->withVersionRows($quoteId, ['0190aaaa0000700080000000000000aa']);

        self::assertTrue($versions->exists($quoteId, '0190aaaa0000700080000000000000aa'));
        self::assertFalse($versions->exists($quoteId, '0190cccc0000700080000000000000cc'));
        self::assertFalse($versions->exists('0190cccc0000700080000000000000cc', '0190aaaa0000700080000000000000aa'));
        self::assertFalse($versions->exists($quoteId, Defaults::LIVE_VERSION));
    }

    public function testANonUuidDoesNotExistAndIsNeverSearched(): void
    {
        self::assertFalse($this->untouchable()->exists('0190aaaa0000700080000000000000bb', 'draft-1'));
    }

    /**
     * A version repository holding exactly these ids.
     *
     * @param list<string> $ids
     */
    private function withVersionRows(string $quoteId, array $ids): QuoteDraftVersions
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $params) use ($quoteId, $ids): int|false {
                self::assertStringContainsString('INNER JOIN `quote`', $sql);
                return $params['quoteId'] === Uuid::fromHexToBytes($quoteId)
                && \in_array(bin2hex($params['versionId']), $ids, strict: true)
                    ? 1
                    : false;
            });

        return new QuoteDraftVersions(
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(ContextBoundGateways::class),
            $connection,
            new NullLogger(),
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
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());

        return new QuoteDraftVersions($quotes, $versions, $gateways, $connection, new NullLogger());
    }
}
