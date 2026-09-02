<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Verifies a browser's grant/deny submission before AgentConsentController
 * acts on it: the handle must resolve to a still-pending record, a customer
 * must be signed in, and — the part Agentic Commerce cannot check for us —
 * that customer's sales channel must match the one the record was registered
 * against.
 *
 * AC's authorize() cannot catch a cross-channel grant on its own: it compares
 * the customer's channel against the channel it resolves from the
 * RequestContext we hand it, i.e. against itself, so it always agrees.
 * hash_equals() rather than `===` because this is a secret-bearing comparison
 * even though sales channel ids are not themselves secret — consistency with
 * the rest of this namespace's handle comparisons.
 */
final readonly class ConsentRequestGuard
{
    public function __construct(
        private PendingAuthorizationStoreInterface $store,
    ) {}

    public function verifiedPending(string $handle, SalesChannelContext $context): ?PendingAuthorization
    {
        $pending = $handle === '' ? null : $this->store->find($handle);

        if ($pending === null || $context->getCustomer() === null) {
            return null;
        }

        return hash_equals($pending->salesChannelId, $context->getSalesChannelId()) ? $pending : null;
    }
}
