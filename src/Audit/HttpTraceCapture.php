<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Builds the single HTTP trace after the controller has produced a response. */
final readonly class HttpTraceCapture
{
    public function __construct(
        private TraceWriterInterface $writer,
        private HttpTraceQuote $quotes,
    ) {}

    public function record(string $route, Request $request, Response $response): void
    {
        $body =
            $response->getStatusCode() >= 400 || $route === HttpTraceRoute::PREFIX . 'quote.request'
                ? HttpTraceBody::json($response->getContent())
                : [];
        $sessionId = $request->attributes->get('sessionId');
        $sessionId = \is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
        $quoteId = $this->quotes->id($route, $request, $body, $sessionId);
        $started = $request->server->get('REQUEST_TIME_FLOAT');
        $duration = \is_numeric($started) ? max(0, (int) round((microtime(true) - (float) $started) * 1000)) : null;

        $this->writer->write(
            new TraceWrite(
                TraceKind::Http,
                [
                    'route' => $route,
                    'method' => $request->getMethod(),
                    'httpStatus' => $response->getStatusCode(),
                    'durationMs' => $duration,
                    'sessionId' => $sessionId,
                    'errorCode' => HttpTraceErrorCode::of($response->getStatusCode(), $body),
                ],
                HttpTraceBody::content($request, $response, HttpTraceRoute::identity($route)),
                $quoteId,
            ),
        );
    }
}
