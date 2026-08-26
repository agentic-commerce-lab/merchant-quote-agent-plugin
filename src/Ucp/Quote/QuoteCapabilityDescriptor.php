<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/**
 * The `com.shopware.quote` vendor capability descriptor.
 *
 * `com.shopware.*` is Shopware's reverse-domain namespace; UCP allows vendor
 * capabilities without upstream approval, and `dev.ucp.*` is reserved for the
 * UCP governing body.
 *
 * The spec and schema documents are served by this plugin and must resolve on
 * the shop's own domain, so the descriptor exists in two forms: {@see paths()}
 * carries the paths (all a capability can know about itself), and
 * {@see resolvedAgainst()} carries absolute URLs, built once the profile is
 * assembled and the base URI is known.
 *
 * Both documents sit deliberately outside the `/ucp/` prefix: the SDK's request
 * listener requires a `UCP-Agent` header on everything below it, but an agent
 * has to read these straight from the discovery document, before it has
 * negotiated anything.
 */
final class QuoteCapabilityDescriptor
{
    public const NAME = 'com.shopware.quote';

    public const VERSION = '2026-04-08';

    public const SPEC_PATH = '/.well-known/ucp/specs/quote.html';

    public const SCHEMA_PATH = '/.well-known/ucp/schemas/quote.openapi.json';

    public static function paths(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(self::NAME, self::VERSION, self::SPEC_PATH, self::SCHEMA_PATH);
    }

    public static function resolvedAgainst(string $baseUri): CapabilityDescriptor
    {
        $base = rtrim($baseUri, characters: '/');

        return new CapabilityDescriptor(self::NAME, self::VERSION, $base . self::SPEC_PATH, $base . self::SCHEMA_PATH);
    }
}
