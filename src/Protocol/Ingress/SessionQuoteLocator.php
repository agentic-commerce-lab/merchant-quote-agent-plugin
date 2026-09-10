<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Ingress;

use MerchantQuoteAgentPlugin\Protocol\Act\ActKey;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Which quote a session id belongs to.
 *
 * SessionId::forQuote() is a UUIDv5 — one way, by design, because a derived
 * id is what makes emission idempotent and what makes the id unguessable to
 * anyone who does not already hold the quote. The price is that the mapping
 * has to be stored somewhere, twice over:
 *
 *   1. The mirror already knows it, from the moment the session has one act.
 *      Indexed, and the answer for every act after the first.
 *   2. A2cnSessionStamp wrote it into the quote's own `customFields` when the
 *      quote was created. This is the answer for the FIRST act, when the
 *      mirror has nothing.
 *
 * The custom-field read is a DAL filter on a JSON path, which Shopware
 * supports for custom fields and which an integration test pins — it is the
 * one assumption in this design the platform could disappoint.
 * ponytail: if that filter ever proves unreliable, replace step 2 with a
 * two-column session→quote table. This class is the only one that changes.
 *
 * `$quotes` is nullable and last, like every other gateway-shaped dependency
 * in this module: the repository is resolved by string id and is absent on a
 * shop without SwagCommercial.
 */
final readonly class SessionQuoteLocator
{
    /** @param EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection>|null $quotes */
    public function __construct(
        private ActStoreInterface $store,
        private ?EntityRepository $quotes = null,
    ) {}

    public function quoteIdFor(string $sessionId): ?string
    {
        $mirrored = $this->store->quoteIdForSession($sessionId);
        if ($mirrored !== null) {
            return $mirrored;
        }

        if ($this->quotes === null) {
            return null;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFields.' . ActKey::SESSION_KEY, $sessionId));
        $criteria->setLimit(1);

        $id = $this->quotes->searchIds($criteria, Context::createDefaultContext())->firstId();

        return \is_string($id) ? $id : null;
    }
}
