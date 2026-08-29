<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use CuyZ\Valinor\Mapper\MappingError;
use MerchantQuoteAgentPlugin\Negotiation\ModelUnavailable;
use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;

/**
 * The extract prompt's JSON to the policy layer's CommentInterpretation.
 *
 * Two vocabularies meet here: the prompt speaks snake_case because that is
 * what reads naturally in a JSON schema a model is asked to follow, and the
 * DTOs speak camelCase because that is PHP. Rekeying is the whole job; the
 * row- and block-level rekeying live in RenamedRows/NegotiationBlock so this
 * class stays a single flat assembly.
 *
 * Anything unusable throws. There is no partial read and no default-and-carry-
 * on: an interpretation the model did not actually produce would put words in
 * the buyer's mouth, and the deciders would act on them.
 */
final class ExtractResponse
{
    private const LINE_CHANGE_KEYS = [
        'line_item_id' => 'lineItemId',
        'quantity' => 'quantity',
        'target_unit_price' => 'targetUnitPrice',
        'remove' => 'remove',
    ];

    private const ADD_PRODUCT_KEYS = [
        'product' => 'product',
        'quantity' => 'quantity',
        'target_unit_price' => 'targetUnitPrice',
    ];

    private function __construct() {}

    /** @throws ModelUnavailable */
    public static function toInterpretation(string $json): CommentInterpretation
    {
        $raw = Json::object($json);

        try {
            // CommentInterpretation::fromArray maps PriceAsk/StructuralAsks off
            // this array's TOP-LEVEL keys (allowSuperfluousKeys lets each leaf
            // mapping ignore the other's keys) — it does not read a nested
            // 'price'/'structural' sub-array, so the rekeyed fields stay flat.
            return CommentInterpretation::fromArray([
                'additionalDiscountPercent' => $raw['additional_discount_percent'] ?? null,
                'bestPriceRequested' => $raw['best_price_requested'] ?? null,
                'lineChanges' => RenamedRows::list($raw, 'line_changes', self::LINE_CHANGE_KEYS),
                'addProducts' => RenamedRows::list($raw, 'add_products', self::ADD_PRODUCT_KEYS),
                'validityUntilIsoDate' => $raw['validity_until'] ?? null,
                'clarificationQuestions' => $raw['clarification_questions'] ?? [],
                'humanReviewRequests' => $raw['human_review_requests'] ?? [],
                'negotiation' => NegotiationBlock::read($raw),
            ]);
        } catch (MappingError|\TypeError|\ValueError $e) {
            throw new ModelUnavailable('The extract response did not match the expected shape.', previous: $e);
        }
    }
}
