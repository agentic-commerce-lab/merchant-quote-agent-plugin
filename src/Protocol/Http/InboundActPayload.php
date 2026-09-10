<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use Symfony\Component\HttpFoundation\Request;

/**
 * Extracts and decodes an Act from an inbound HTTP request body.
 *
 * Split out of A2cnMessagesController to keep the controller within the
 * per-class cyclomatic-complexity gate.
 */
final class InboundActPayload
{
    private function __construct() {}

    public static function from(Request $request): ?Act
    {
        $payload = json_decode($request->getContent(), associative: true);

        return \is_array($payload) ? Act::fromArray($payload) : null;
    }
}
