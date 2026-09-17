<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The dashboard's Export button, serving exactly what
 * `merchant-quote-agent:export` writes to stdout -- same stream, same
 * allowlist, same half-open range -- as a downloadable file, so a merchant who
 * has never opened a shell can still send their records to Shopware.
 *
 * Read-gated on `merchant_quote_agent_decision:read` and nothing further: this
 * exposes a pseudonymized SUBSET of what the decision list and detail pages
 * already render to the same viewer. A second privilege would suggest the
 * export reveals something those pages do not, and it does not.
 *
 * `from` and `to` are validated here rather than trusted from the caller even
 * though the dashboard computes them: this is an authenticated HTTP endpoint,
 * and an unparseable date reaching DateTimeImmutable throws a 500 where a 400
 * is the honest answer.
 *
 * Streamed, because a 90-day export on a busy shop is thousands of lines and
 * there is no reason to hold the whole file in memory to hand it to a
 * download. The trade-off is the usual one: headers are sent before the first
 * record is read, so a failure mid-iteration truncates the file rather than
 * producing an error page. A truncated JSONL is detectable (the merchant sees
 * fewer lines than the dashboard's count); a 30MB string in PHP memory on a
 * shared host is not.
 */
final readonly class DecisionExportController
{
    public function __construct(
        private DecisionExportStream $export,
    ) {}

    /**
     * @throws \Random\RandomException
     */
    #[Route(
        path: '/api/_action/merchant-quote-agent/decision-export',
        name: 'api.action.merchant_quote_agent.decision_export',
        defaults: ['_routeScope' => ['api'], '_acl' => ['merchant_quote_agent_decision:read']],
        methods: ['GET'],
    )]
    public function export(Request $request, Context $context): Response
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        if (!\is_string($from) || !\is_string($to) || $from === '' || $to === '') {
            return self::refuse('Both "from" and "to" are required.');
        }

        try {
            $start = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
        } catch (\Exception $e) {
            return self::refuse(\sprintf('"%s" or "%s" could not be read as a date: %s', $from, $to, $e->getMessage()));
        }

        if ($end <= $start) {
            return self::refuse('"to" must be after "from"; the range is half-open.');
        }

        // Free text is INCLUDED unless the caller opts out, the opposite of the
        // console command's default. The dashboard's menu makes both readings
        // one click apart and labels what each one sends, which is the
        // safeguard the command has to get from a flag nobody types.
        $freeText = $request->query->get('comments') !== '0';
        $lines = $this->export->lines($start, $end, $freeText, $context);

        $response = new StreamedResponse(static function () use ($lines): void {
            foreach ($lines as $line) {
                echo $line, "\n";
            }
        });

        $response->headers->set('Content-Type', 'application/x-ndjson');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s"', \sprintf(
            'merchant-quote-agent-%s-to-%s.jsonl',
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
        )));

        return $response;
    }

    private static function refuse(string $message): JsonResponse
    {
        return new JsonResponse(['errors' => [['detail' => $message]]], Response::HTTP_BAD_REQUEST);
    }
}
