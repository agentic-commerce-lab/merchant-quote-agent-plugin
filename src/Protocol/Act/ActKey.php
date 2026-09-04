<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Act;

/**
 * The quote `customFields` key convention for the A2CN act chain.
 *
 * One act per TOP-LEVEL key, because Shopware merges customFields shallowly on
 * update (see Bridge\QuoteWriter): separate keys make the buyer's append and
 * ours conflict-free, where a single `a2cn.acts[]` blob would lose one of two
 * concurrent writes.
 *
 * The key carries a zero-padded index AND the writer's role
 * (`a2cn_act_0003_b` / `a2cn_act_0003_s`). The index makes lexical order equal
 * chronological order; the role means the two parties can never target the same
 * key, so a concurrent append at the same sequence leaves both acts intact
 * instead of one silently overwriting the other. A duplicate sequence number is
 * then merely visible, and DuplicateSequenceCheck reports it rather than
 * evidence being destroyed.
 */
final class ActKey
{
    public const PREFIX = 'a2cn_act_';
    public const SESSION_KEY = 'a2cn_session';

    /**
     * The widest value a 4-digit zero-padded index can represent. This bound is
     * what makes the fixed pad width safe: past it, `str_pad` stops padding and
     * a 5-digit index (`10000`) sorts lexically BEFORE `9999`, breaking the
     * "lexical order equals chronological order" invariant this class promises.
     */
    public const MAX_SEQUENCE = 9999;

    private const INDEX_WIDTH = 4;

    private function __construct() {}

    /** @throws OutOfRangeSequence if the sequence would not sort correctly at this pad width. */
    public static function for(int $sequence, ActRole $role): string
    {
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new OutOfRangeSequence(\sprintf('A2CN act sequence out of range: %d', $sequence));
        }

        return self::PREFIX . str_pad((string) $sequence, self::INDEX_WIDTH, '0', \STR_PAD_LEFT) . '_' . $role->value;
    }

    public static function isActKey(string $key): bool
    {
        return str_starts_with($key, self::PREFIX);
    }
}
