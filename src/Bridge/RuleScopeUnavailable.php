<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

/**
 * This quote cannot be turned into a rule scope right now.
 *
 * Not a misconfiguration and not a bug: the common cause is a customer with no
 * active shipping address, which QuoteToCartConverter refuses to convert. The
 * assignment ladder treats it as "the rule rung does not apply" and falls
 * through, rather than escalating the quote to a human.
 */
final class RuleScopeUnavailable extends \RuntimeException {}
