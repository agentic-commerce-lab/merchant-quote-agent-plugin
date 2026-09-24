<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Review\DraftModePipeline;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

/**
 * @mago-expect lint:excessive-parameter-list
 * A plain holder of the seven things DraftModePipelineTest asserts on.
 */
final readonly class DraftModeHarness
{
    public function __construct(
        public DraftModePipeline $pipeline,
        public RecordingInnerPipeline $inner,
        public FakeQuoteGateway $live,
        public FakeDraftVersions $versions,
        public FakeReviewStore $reviews,
        public RecordingNotifier $notifier,
        public QuoteSnapshot $snapshot,
    ) {}
}
