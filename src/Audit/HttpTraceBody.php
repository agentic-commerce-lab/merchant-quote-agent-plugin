<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only JSON refusal codes enter meta; raw bodies stay behind the content gate. */
final class HttpTraceBody
{
    private function __construct() {}

    /** @return array<array-key, mixed> */
    public static function json(string|false $content): array
    {
        $decoded = \is_string($content) ? json_decode($content, true) : null;

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string|null>|null */
    public static function content(Request $request, Response $response, bool $metadataOnly): ?array
    {
        if ($metadataOnly || !$request->isMethod('POST') && $response->getStatusCode() < 400) {
            return null;
        }

        return [
            'requestBody' => $request->isMethod('POST') ? $request->getContent() : null,
            'responseBody' => $response->getContent() ?: '',
        ];
    }
}
