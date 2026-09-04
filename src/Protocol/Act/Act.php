<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * One act, as it sits on the wire.
 *
 * The act IS its raw array: `terms` is hashed wholesale, so a counterparty term
 * this plugin does not model must survive verbatim or the hash we verify is not
 * the hash they signed. Typed accessors read over that array; `fromArray()` is
 * the tolerant gate.
 *
 * `fromArray()` returns null rather than throwing. A malformed buyer act is an
 * evidence problem — it belongs in a violation, not in an exception that would
 * interrupt quote servicing.
 *
 * @mago-expect lint:too-many-methods
 * The interface this task specifies is one typed accessor per envelope field
 * (message type, ids, sender, timestamp, terms, proof) plus `raw()` and the
 * two derived reads used by other tasks (`isOffer()`, `lineQuantities()`).
 * Splitting those accessors across classes would not shrink them, it would
 * relocate the same flat read surface and cost callers an extra class to
 * find the field on — see SwagCommercialBuyerQuoteGateway for the same
 * precedent (many thin methods over one bounded interface).
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10); every branch here is real
 * tolerant-parsing logic in `fromArray()` (size cap, per-field type checks,
 * the optional `terms` check) plus the skip-on-malformed-line loop in
 * `lineQuantities()`. Every other method is a single-branch nullable read.
 * Splitting `fromArray()`'s checks into helper methods would redistribute
 * the same branches without removing any of them.
 */
final readonly class Act
{
    /** Acts that consume a negotiation round. Session establishment does not. */
    public const OFFER_TYPES = ['offer', 'counteroffer'];

    /**
     * A cap on what we will read off a quote. The counterparty controls this
     * value, and an unbounded act would be an unbounded hash, log line and row.
     * ponytail: 64 KiB is generous for terms; raise it only against a real act
     * that needs more.
     */
    public const MAX_ENCODED_BYTES = 65536;

    /** @param array<string, mixed> $raw */
    private function __construct(
        private array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $encoded = json_encode($raw);
        if ($encoded === false || \strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return null;
        }

        foreach ([
            'message_type',
            'message_id',
            'session_id',
            'sender_did',
            'sender_verification_method',
            'timestamp',
            'protocol_act_hash',
            'protocol_act_signature',
        ] as $field) {
            if (!\is_string($raw[$field] ?? null)) {
                return null;
            }
        }

        $sequence = $raw['sequence_number'] ?? null;
        if (!\is_int($sequence) || $sequence < 1 || $sequence > ActKey::MAX_SEQUENCE) {
            return null;
        }

        if (\array_key_exists('terms', $raw) && !\is_array($raw['terms'])) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        return new self($raw);
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->raw;
    }

    public function messageType(): string
    {
        return $this->string('message_type');
    }

    public function messageId(): string
    {
        return $this->string('message_id');
    }

    public function sessionId(): string
    {
        return $this->string('session_id');
    }

    public function senderDid(): string
    {
        return $this->string('sender_did');
    }

    public function verificationMethod(): string
    {
        return $this->string('sender_verification_method');
    }

    public function timestamp(): string
    {
        return $this->string('timestamp');
    }

    public function hash(): string
    {
        return $this->string('protocol_act_hash');
    }

    public function signature(): string
    {
        return $this->string('protocol_act_signature');
    }

    public function sequenceNumber(): int
    {
        $value = $this->raw['sequence_number'] ?? null;

        return \is_int($value) ? $value : 0;
    }

    public function roundNumber(): ?int
    {
        $value = $this->raw['round_number'] ?? null;

        return \is_int($value) ? $value : null;
    }

    public function senderAgentId(): ?string
    {
        $value = $this->raw['sender_agent_id'] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function expiresAt(): ?string
    {
        $value = $this->raw['expires_at'] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @return array<string, mixed>|null */
    public function terms(): ?array
    {
        $value = $this->raw['terms'] ?? null;

        /** @var array<string, mixed>|null $value */
        return \is_array($value) ? $value : null;
    }

    public function isOffer(): bool
    {
        return \in_array($this->messageType(), self::OFFER_TYPES, strict: true);
    }

    /**
     * The act's line items as `id => quantity`, for the structural cross-check.
     * A line without a string id or int quantity is skipped: the check compares
     * what the act actually claims, and a claim we cannot read is not a claim.
     *
     * @return array<string, int>
     */
    public function lineQuantities(): array
    {
        $lines = $this->terms()['line_items'] ?? null;
        if (!\is_array($lines)) {
            return [];
        }

        $quantities = [];
        foreach ($lines as $line) {
            if (!\is_array($line)) {
                continue;
            }
            $id = $line['id'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (\is_string($id) && \is_int($quantity)) {
                $quantities[$id] = $quantity;
            }
        }

        return $quantities;
    }

    private function string(string $field): string
    {
        $value = $this->raw[$field] ?? null;

        return \is_string($value) ? $value : '';
    }
}
