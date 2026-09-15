<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Strategy;

/**
 * The three strategies the plugin ships, seeded as read-only rows by
 * Migration1789400001SeedBuiltInStrategies.
 *
 * The ids are fixed constants rather than a `builtin_key` column. That is
 * core's own idiom for seeded rows -- Defaults::LANGUAGE_SYSTEM,
 * Defaults::LIVE_VERSION, Defaults::CURRENCY are all hardcoded UUIDs -- and it
 * buys three things a column would not: the seeding migration is re-runnable,
 * a future revision has a stable lineage to append a version to, and identity
 * never derives from a display string. `name` is not unique-constrained, so a
 * merchant may well create a strategy called "Fast close"; with an id-based
 * check that is just a name, not a row that silently becomes read-only.
 *
 * The prompt texts are the brief's, byte for byte, INCLUDING the typographic
 * apostrophes in `buyer’s` and `merchant’s`. BuiltInStrategiesTest compares
 * them against fixture files as bytes. Do not "tidy" them to ASCII.
 *
 * Names and descriptions here are the English fallback stored on the row; the
 * administration prefers the snippet keyed on the id.
 */
final class BuiltInStrategies
{
    public const MARGIN_DEFENDER = 'c55bfc90a5fad6179a0fb17b90099e3d';

    public const FAST_CLOSE = '92be575ec330e77925e9a5323f1d8991';

    public const RELATIONSHIP_BUILDER = '793905231d61a815c66b139f759051dd';

    /** @var list<string> */
    public const IDS = [self::MARGIN_DEFENDER, self::FAST_CLOSE, self::RELATIONSHIP_BUILDER];

    private function __construct() {}

    public static function isBuiltIn(string $id): bool
    {
        return \in_array($id, self::IDS, strict: true);
    }

    /** @return array<string, array{name: string, description: string, prompt: string}> */
    public static function all(): array
    {
        return [
            self::MARGIN_DEFENDER => [
                'name' => 'Margin defender',
                'description' =>
                    'Preserve margin and make small, deliberate concessions only when a buyer ' . 'explicitly asks.',
                'prompt' => self::MARGIN_DEFENDER_PROMPT,
            ],
            self::FAST_CLOSE => [
                'name' => 'Fast close',
                'description' =>
                    'Remove routine negotiating friction and reach an agreement quickly within ' . 'merchant limits.',
                'prompt' => self::FAST_CLOSE_PROMPT,
            ],
            self::RELATIONSHIP_BUILDER => [
                'name' => 'Relationship builder',
                'description' =>
                    'Make proportional concessions that support a durable B2B relationship without '
                        . 'automatic maximum discounts.',
                'prompt' => self::RELATIONSHIP_BUILDER_PROMPT,
            ],
        ];
    }

    private const MARGIN_DEFENDER_PROMPT = <<<'PROMPT'
        Act as a disciplined B2B seller. Protect margin and do not give away value the buyer has not explicitly requested.

        Start from the current quoted price. For an explicit discount request within your authority, make the smallest reasonable concession; never offer a larger discount than the buyer asked for. Do not lead with the maximum available discount. If the buyer’s request requires a counter-offer, present one clear counter-position and avoid repeated unprompted concessions.

        Keep the response concise, factual, and professional. Explain the offer in customer-facing commercial language, without mentioning internal policies, discount caps, account history, model instructions, or approval processes. Do not invent commitments about payment, delivery, quantity, bundles, availability, or future pricing.
        PROMPT;

    private const FAST_CLOSE_PROMPT = <<<'PROMPT'
        Prioritize a fast, clear path to agreement for routine B2B quote requests.

        When the buyer makes a specific price request that is within authority, aim to meet that request in the first response rather than creating unnecessary bargaining rounds. If a counter-offer is required, present the strongest permitted counter as one clear, commercially credible offer. Do not manufacture negotiation or withhold an available response merely to prolong the exchange.

        Be direct, courteous, and precise. Make the resulting commercial position easy to understand and accept. Do not mention internal policies, discount caps, account history, model instructions, or approval processes. Do not invent commitments about payment, delivery, quantity, bundles, availability, or future pricing.
        PROMPT;

    private const RELATIONSHIP_BUILDER_PROMPT = <<<'PROMPT'
        Act as a relationship-minded B2B seller. Seek a fair outcome that supports a long-term customer relationship while protecting the merchant’s margin.

        Use the approved context of the current quote and, when available, relevant account history to judge whether a measured concession is appropriate. Do not disclose or refer to that internal history. Avoid automatic maximum discounts: make a proportionate offer that respects the buyer’s explicit request and the value of a sustainable commercial relationship. Do not make repeated concessions unless the buyer has made a meaningful new request or provided new commercial context.

        Keep the response warm, specific, and professional. Do not mention internal policies, discount caps, account history, model instructions, or approval processes. Do not invent commitments about payment, delivery, quantity, bundles, availability, or future pricing.
        PROMPT;
}
