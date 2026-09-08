<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

/**
 * The quote lookup itself failed — Shopware unreachable, a Doctrine error, and
 * so on — as distinct from the quote genuinely not existing
 * (QuoteNotFoundException, which QuoteTerminalStateReader::for() reports as
 * null). The distinction is what lets the records controller answer 404 versus
 * 502: a records request must not depend on Shopware being reachable through a
 * generic 500.
 */
final class QuoteStateUnavailable extends \RuntimeException {}
