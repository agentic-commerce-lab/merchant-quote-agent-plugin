<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/**
 * Why a review request was refused with a 400: the code the review card keys
 * its snippet on (`merchant-quote-agent.review.error.invalid.<value>`), beside
 * the message it falls back to. review.check.mjs pins these values to the
 * card and to both snippet files, so a new case cannot ship without copy.
 */
enum InvalidReviewReason: string
{
    /** The body is not JSON, or not the shape the route maps. */
    case Malformed = 'malformed';
    case NotAnAdminUser = 'not_an_admin_user';
    case EmptyReply = 'empty_reply';
    case DiscountOutOfRange = 'discount_out_of_range';
    case NegativePrice = 'negative_price';
    case DateFormat = 'date_format';
    case DateInPast = 'date_in_past';
    case PriceIncrease = 'price_increase';
    case NoPrices = 'no_prices';
    case UnknownLine = 'unknown_line';
    case CommentTooLong = 'comment_too_long';
    case EmptyFeedback = 'empty_feedback';
}
