<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Config\InvalidQuoteAgentConfiguration;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Negotiation\ReductionForPass;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Negotiation\SnapshotAdapter;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use Psr\Log\LoggerInterface;

final readonly class DraftReply
{
    public function __construct(
        private ReplyComposer $composer,
        private QuoteAgentSettingsSource $settings,
        private LoggerInterface $logger,
    ) {}

    /** @throws InvalidReviewRequest */
    public function compose(PendingDraft $pending, QuoteSnapshot $after): ?string
    {
        if ($pending->draft === null) {
            return null;
        }

        try {
            $settings = $this->settings->forSalesChannel($pending->live->identity->salesChannelId);
        } catch (InvalidQuoteAgentConfiguration $e) {
            $this->logger->warning('The reply could not be re-drafted: the sales channel configuration is unusable.', [
                'quoteId' => $pending->record->quoteId,
                'exception' => $e,
            ]);

            return null;
        }

        if ($settings === null) {
            return null;
        }

        $granted = abs($after->totals->totalNet - $pending->live->totals->totalNet) > Epsilon::MONEY;
        [$percent, $disagreed] = ReductionForPass::of(
            SnapshotAdapter::anchored($pending->live)->totalNet,
            $after->totals->totalNet,
            $granted,
        );

        if ($disagreed) {
            throw InvalidReviewRequest::because('These prices would raise the total above what the quote shows now.');
        }

        [$text] = $this->composer->reword(
            $settings,
            $after,
            SnapshotAdapter::conversation($pending->live)->newestBuyerText(),
            $percent,
        );

        return $text;
    }
}
