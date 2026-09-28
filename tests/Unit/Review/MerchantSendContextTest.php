<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Review\MerchantSendContext;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;

final class MerchantSendContextTest extends TestCase
{
    /**
     * Any admin-API caller can set `sw-version-id`. The widened permissions
     * this context carries must only ever write the live quote — never a
     * version the caller picked, the snapshot lane included.
     */
    public function testARequestBoundToAnotherVersionStillYieldsALiveContext(): void
    {
        $request = new Context(new AdminApiSource('user-1'), versionId: '0190aaaa0000700080000000000000aa');

        $context = MerchantSendContext::from($request);

        self::assertSame(Defaults::LIVE_VERSION, $context->getVersionId());
    }

    public function testTheReviewerStaysTheAuthor(): void
    {
        $source = MerchantSendContext::from(new Context(new AdminApiSource('user-1')))->getSource();

        self::assertInstanceOf(AdminApiSource::class, $source);
        self::assertSame('user-1', $source->getUserId());
        self::assertTrue($source->isAllowed('quote_comment:create'));
    }
}
