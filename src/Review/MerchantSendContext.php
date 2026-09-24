<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;

/**
 * The review route checked decision:update before Send. Its reviewer role
 * deliberately need not grant quote_comment:create or quote:update too.
 * QuoteCommenter opens a nested user scope, so a system scope alone cannot
 * carry that authority across its write. A fresh admin source preserves the
 * reviewer's user id as comment author without changing the request context.
 */
final class MerchantSendContext
{
    private function __construct() {}

    /** @throws InvalidReviewRequest */
    public static function from(Context $request): Context
    {
        $original = $request->getSource();

        if (!$original instanceof AdminApiSource || $original->getUserId() === null) {
            throw InvalidReviewRequest::because('Sending a draft requires an administration user.');
        }

        $source = new AdminApiSource($original->getUserId(), $original->getIntegrationId());
        $source->setPermissions(array_values(array_unique([
            ...$original->getPermissions(),
            'quote_comment:create',
            'quote_history:create',
            'quote:update',
        ])));

        $context = new Context(
            $source,
            $request->getRuleIds(),
            $request->getCurrencyId(),
            $request->getLanguageIdChain(),
            $request->getVersionId(),
            $request->getCurrencyFactor(),
            $request->considerInheritance(),
            $request->getTaxState(),
            $request->getRounding(),
        );

        foreach ($request->getExtensions() as $key => $extension) {
            $context->addExtension($key, $extension);
        }

        return $context;
    }
}
