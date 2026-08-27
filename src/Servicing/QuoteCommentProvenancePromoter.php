<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\MerchantQuoteAgentPlugin;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;

final class QuoteCommentProvenancePromoter
{
    private function __construct() {}

    public static function promote(EntityWrittenEvent $event, QuoteGatewayInterface $gateway): void
    {
        foreach ($event->getWriteResults() as $result) {
            if (!QuoteCommentWriteResultInspector::isLiveInsert($result)) {
                continue;
            }

            $quoteId = QuoteCommentWriteResultInspector::quoteId($result);
            $commentId = QuoteCommentWriteResultInspector::commentId($result);
            if ($quoteId === null || $commentId === null) {
                continue;
            }

            $gateway->updateQuote($quoteId, new QuoteUpdate(customFields: [
                MerchantQuoteAgentPlugin::LAST_AGENT_COMMENT_ID => $commentId,
            ]));
        }
    }
}
