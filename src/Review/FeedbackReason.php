<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

/** Why the agent's work was not right. The admin's review.ts FEEDBACK_REASONS mirrors these values. */
enum FeedbackReason: string
{
    case WrongPrice = 'wrong_price';
    case WrongWording = 'wrong_wording';
    case MisunderstoodBuyer = 'misunderstood_buyer';
    case ShouldHaveEscalated = 'should_have_escalated';
    case ShouldNotHaveEscalated = 'should_not_have_escalated';
    case Other = 'other';
}
