<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Verifies a browser's grant/deny submission before AgentConsentController
 * acts on it: the handle must resolve to a still-pending record, a customer
 * must be signed in, the submitted form token must still match the session's
 * CURRENT handle, and — the part Agentic Commerce cannot check for us — that
 * customer's sales channel must match the one the record was registered
 * against.
 *
 * AC's authorize() cannot catch a cross-channel grant on its own: it compares
 * the customer's channel against the channel it resolves from the
 * RequestContext we hand it, i.e. against itself, so it always agrees. The
 * token check exists for a related reason: without it, a handle swapped into
 * the session by another tab after the consent page was rendered (a
 * top-level GET to `?request_uri=`, which `SameSite=lax` permits) would let
 * an unwitting Allow click on the still-displayed page grant a DIFFERENT
 * request than the one the customer read.
 *
 * `matchesChannel()` is exposed separately from `verifiedPending()` so the
 * GET route can bind the channel too, without re-`find()`-ing the record or
 * duplicating the customer check GET already does its own way (an anonymous
 * visitor there goes to login, not straight to "expired").
 */
final readonly class ConsentRequestGuard
{
    public function __construct(
        private PendingAuthorizationStoreInterface $store,
    ) {}

    public function verifiedPending(
        string $handle,
        SalesChannelContext $context,
        #[\SensitiveParameter]
        string $token,
    ): ?PendingAuthorization {
        $pending = $handle === '' ? null : $this->store->find($handle);

        if ($pending === null || $context->getCustomer() === null) {
            return null;
        }

        if (!hash_equals($this->formToken($handle), $token)) {
            return null;
        }

        return $this->matchesChannel($pending, $context) ? $pending : null;
    }

    /**
     * `hash_equals()` rather than `===`: not for timing — sales channel ids
     * are not secrets — but because it is an exact string comparison that
     * cannot silently loosen into type juggling, and it matches how the rest
     * of this namespace compares identifiers.
     */
    public function matchesChannel(PendingAuthorization $pending, SalesChannelContext $context): bool
    {
        return hash_equals($pending->salesChannelId, $context->getSalesChannelId());
    }

    /**
     * Binds a rendered consent page to the handle the session held at render
     * time. Not a secret — the handle itself is 256 bits of random_bytes()
     * and this is only its hash — it just has to prove the submitted form
     * came from the page rendered for the CURRENT session handle, so a
     * handle swapped in by another tab after the page was rendered no longer
     * matches.
     */
    public function formToken(string $handle): string
    {
        return hash('sha256', $handle);
    }
}
