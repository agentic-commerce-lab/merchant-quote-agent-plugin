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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * A delete in the live version's context deletes the live quote, and a merge
 * of the snapshot lane replays SwagCommercial's "last sent" copy over it — so
 * both refuse anything but a draft id before a repository is ever reached.
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
