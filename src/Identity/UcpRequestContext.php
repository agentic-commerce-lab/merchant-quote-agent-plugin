<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity;

use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * The SDK's per-request agent context, read off the request attribute its
 * `RequestContextListener` sets.
 *
 * Lives here rather than in each controller because the refusal below is a
 * statement about the SDK's own behaviour — the listener matches on the
 * `/ucp/` prefix and builds a context for nothing outside it — and that fact
 * should be recorded once. It was previously duplicated verbatim, message and
 * all, in UcpQuoteController and AgentAuthorizationRequestController.
 *
 * Beside {@see UcpAgentHeader} because it is the same kind of thing: a small
 * reader of one UCP request detail. `Identity` owns it and `Ucp` already
 * depends on `Identity`, so this adds no cycle between the two.
 */
final class UcpRequestContext
{
    public const ATTRIBUTE = 'ucp_request_context';

    private function __construct() {}

    /**
     * @throws ConfigurationException when the route is registered outside the
     *     prefix the SDK listener matches, so no context was ever built
     */
    public static function of(Request $request): RequestContext
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        if (!$context instanceof RequestContext) {
            throw new ConfigurationException(
                'No UCP request context on the request. The SDK listener only builds one below /ucp/, '
                . 'so this route is registered outside the prefix it depends on.',
            );
        }

        return $context;
    }
}
