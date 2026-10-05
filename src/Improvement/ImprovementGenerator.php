<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Improvement;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * One nightly tick, across every sales channel.
 *
 * A null sales-channel id is the global fallback and needs no separate pass
 * of its own: ImprovementSettingsReader and QuoteAgentSettingsSource both read
 * through SystemConfigService, which already falls back to the global value
 * for a channel with no override of its own (Shopware's own config
 * semantics) -- so looping over the shop's actual channel ids is enough.
 *
 * A channel that throws (ImprovementRunner's own failed-run contract) stops
 * this tick for every channel after it; the next tick picks each channel back
 * up from its own last completed run, so nothing already completed is lost or
 * repeated.
 */
final readonly class ImprovementGenerator
{
    public function __construct(
        private EntityRepository $salesChannels,
        private ImprovementRunner $runner,
    ) {}

    /** @throws \Throwable propagated from ImprovementRunner::run(); see its own docblock. */
    public function generate(\DateTimeImmutable $now): void
    {
        $context = Context::createDefaultContext();

        foreach ($this->channelIds($context) as $salesChannelId) {
            $this->runner->run($salesChannelId, $now);
        }
    }

    /** @return list<string> */
    private function channelIds(Context $context): array
    {
        return array_values($this->salesChannels->search(new Criteria(), $context)->getEntities()->getIds());
    }
}
