<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Protocol\Ingress\SessionQuoteLocator;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/** Resolves the quote attached to an HTTP event where the route permits it. */
final readonly class HttpTraceQuote
{
    public function __construct(
        private ?SessionQuoteLocator $sessions = null,
    ) {}

    /** @param array<array-key, mixed> $body */
    public function id(string $route, Request $request, array $body, ?string $sessionId): ?string
    {
        $id = $request->attributes->get('id');
        if (\is_string($id) && Uuid::isValid($id)) {
            return $id;
        }

        if ($sessionId !== null) {
            $id = $this->sessions?->quoteIdFor($sessionId);

            return \is_string($id) && Uuid::isValid($id) ? $id : null;
        }

        if ($route === HttpTraceRoute::PREFIX . 'quote.request') {
            $id = $body['id'] ?? $body['data']['id'] ?? null;

            return \is_string($id) && Uuid::isValid($id) ? $id : null;
        }

        return null;
    }
}
