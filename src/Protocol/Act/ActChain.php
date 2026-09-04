<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * The shared act chain, as stored on the Shopware quote.
 *
 * Read-only and derived: the quote's `customFields` are the authoritative
 * chain, this is the ordered view of them. Plain lexical sort over the act keys
 * is a total order every reader agrees on — which is what keeps
 * `offer_chain_hash` agreed even when a concurrent append leaves two acts at
 * the same sequence.
 *
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * This task's interface is one query per read this plugin needs over the
 * ordered chain (last act, last of ours, first of theirs, buyer-only acts,
 * duplicate sequences, next sequence/round, length-cap and offer presence).
 * Each is a single short loop or a one-line derivation; the two rules both
 * aggregate those loops per class (thresholds 10 / method-count). Splitting
 * the queries across classes would not remove a single branch, only relocate
 * it — see SwagCommercialBuyerQuoteGateway for the same precedent (many small
 * methods over one bounded interface).
 */
final readonly class ActChain
{
    /**
     * ponytail: a chain longer than this is not a negotiation, and reading it
     * unbounded would let a counterparty size our work. Read up to the cap and
     * let the caller report it.
     */
    public const MAX_ACTS = 512;

    /**
     * @param list<Act> $acts
     */
    private function __construct(
        private array $acts,
        private ?string $sessionId,
        private bool $exceedsLengthCap,
    ) {}

    /**
     * @param array<string, mixed>|null $customFields
     */
    public static function read(?array $customFields): self
    {
        $session = $customFields[ActKey::SESSION_KEY] ?? null;
        if (!\is_string($session) || $session === '') {
            return new self([], null, false);
        }

        $keys = array_filter(array_keys($customFields ?? []), ActKey::isActKey(...));
        sort($keys);
        $overflow = \count($keys) > self::MAX_ACTS;

        $acts = [];
        foreach (\array_slice($keys, 0, self::MAX_ACTS) as $key) {
            $value = $customFields[$key] ?? null;
            $act = \is_array($value) ? Act::fromArray($value) : null;
            if ($act !== null) {
                $acts[] = $act;
            }
        }

        return new self($acts, $session, $overflow);
    }

    /** @return list<Act> */
    public function acts(): array
    {
        return $this->acts;
    }

    public function isEmpty(): bool
    {
        return $this->acts === [];
    }

    public function hasSession(): bool
    {
        return $this->sessionId !== null;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /** The session id the acts themselves claim, which is not necessarily ours. */
    public function claimedSessionId(): ?string
    {
        return $this->acts[0]?->sessionId();
    }

    public function exceedsLengthCap(): bool
    {
        return $this->exceedsLengthCap;
    }

    public function nextSequence(): int
    {
        $highest = array_reduce(
            $this->acts,
            static fn(int $carry, Act $act): int => max($carry, $act->sequenceNumber()),
            0,
        );

        return $highest + 1;
    }

    public function nextRound(): int
    {
        return \count(array_filter($this->acts, static fn(Act $act): bool => $act->isOffer())) + 1;
    }

    public function last(): ?Act
    {
        return $this->acts === [] ? null : $this->acts[\count($this->acts) - 1];
    }

    public function lastSellerAct(string $sellerDid): ?Act
    {
        $ours = array_values(array_filter($this->acts, static fn(Act $act): bool => $act->senderDid() === $sellerDid));

        return $ours === [] ? null : $ours[\count($ours) - 1];
    }

    /** The counterparty's first act — whoever we did not sign for. */
    public function firstForeignAct(string $sellerDid): ?Act
    {
        return $this->buyerActs($sellerDid)[0] ?? null;
    }

    /** @return list<Act> */
    public function buyerActs(string $sellerDid): array
    {
        return array_values(array_filter($this->acts, static fn(Act $act): bool => $act->senderDid() !== $sellerDid));
    }

    /** @return list<int> */
    public function duplicateSequences(): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($this->acts as $act) {
            $sequence = $act->sequenceNumber();
            if (isset($seen[$sequence])) {
                $duplicates[$sequence] = $sequence;
            }
            $seen[$sequence] = true;
        }

        return array_values($duplicates);
    }

    public function hasOffer(): bool
    {
        return array_filter($this->acts, static fn(Act $act): bool => $act->isOffer()) !== [];
    }
}
