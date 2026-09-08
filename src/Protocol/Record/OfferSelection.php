<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Record;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;

/**
 * The one pass over an act chain both record builders need: which acts were
 * offers, which one came first and last, and the session id they all share.
 *
 * A real seam, not a grab-bag: TransactionRecord and AuditLog both open by
 * asking "what is the shape of this chain", and computing it once here is
 * what keeps `build()` in each of them a flat assembly of fields instead of a
 * second copy of this same handful of null/empty checks.
 */
final readonly class OfferSelection
{
    /** @var list<Act> */
    public array $offers;

    public ?Act $finalOffer;

    public ?Act $firstOffer;

    public ?Act $firstAct;

    public ?Act $lastAct;

    public string $sessionId;

    /** @param list<Act> $acts */
    public function __construct(array $acts)
    {
        $this->offers = array_values(array_filter($acts, static fn(Act $act): bool => $act->isOffer()));
        $this->finalOffer = $this->offers === [] ? null : $this->offers[\count($this->offers) - 1];
        $this->firstOffer = $this->offers[0] ?? null;
        $this->firstAct = $acts[0] ?? null;
        $this->lastAct = $acts === [] ? null : $acts[\count($acts) - 1];
        $this->sessionId = $this->firstAct?->sessionId() ?? '';
    }
}
