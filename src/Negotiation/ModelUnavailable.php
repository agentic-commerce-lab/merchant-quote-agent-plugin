<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The model could not be reached, or answered with something unusable.
 *
 * Always escalates. There is deliberately no fall back to rules-only on error:
 * a shop whose negotiation quietly changes character when a provider has a bad
 * minute is the silent behaviour change this whole design exists to remove.
 */
final class ModelUnavailable extends \RuntimeException {}
