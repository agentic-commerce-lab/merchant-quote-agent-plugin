<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditEvidence;
use MerchantQuoteAgentPlugin\Protocol\Record\AuditLog;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParties;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordSubject;
use MerchantQuoteAgentPlugin\Protocol\Record\SessionOutcome;
use MerchantQuoteAgentPlugin\Protocol\Record\TransactionRecord;
use MerchantQuoteAgentPlugin\Protocol\Store\ActStoreInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Record selection: which of the two end-of-session records a quote's state
 * calls for, or whether the session is still live — split out of
 * A2cnRecordsController, whose own job is the two routes themselves and the
 * store/gateway orchestration in front of this class. Who the parties are is
 * its own question, answered by RecordPartiesResolver; splitting on both
 * seams is what keeps every one of these classes under the per-class
 * cyclomatic-complexity gate.
 */
final readonly class RecordResponder
{
    public function __construct(
        private ActStoreInterface $store,
        private TransactionRecord $transactionRecord,
        private AuditLog $auditLog,
        private RecordPartiesResolver $parties,
    ) {}

    /** @param list<Act> $acts */
    public function respond(string $sessionId, array $acts, QuoteTerminalState $quote): JsonResponse
    {
        $parties = $this->parties->resolve($acts, $quote);

        $acceptance = $quote->acceptance;
        if ($acceptance !== null) {
            return $this->accepted($sessionId, $acts, $acceptance, $parties, $quote);
        }

        $outcome = SessionOutcome::for($quote->state, $quote->expired);
        if ($outcome === null) {
            return JsonEnvelope::noStore(['status' => 'session_live', 'session_id' => $sessionId], 409);
        }

        return JsonEnvelope::noStore($this->auditLog->build(
            $parties,
            $acts,
            $outcome,
            new AuditEvidence($this->store->listViolations($sessionId), $this->store->listReceipts($sessionId)),
            (new \DateTimeImmutable())->format(\DATE_ATOM),
        ));
    }

    /** @param list<Act> $acts */
    private function accepted(
        string $sessionId,
        array $acts,
        Act $acceptance,
        RecordParties $parties,
        QuoteTerminalState $quote,
    ): JsonResponse {
        // An acceptance with no prior offer is itself a protocol violation (the
        // buyer's chain, which we do not fully trust). Reporting that beats
        // manufacturing a record with empty agreed terms, which would be
        // indistinguishable from a bug in this code.
        $hasOffer = array_filter($acts, static fn(Act $act): bool => $act->isOffer()) !== [];
        if (!$hasOffer) {
            return JsonEnvelope::noStore([
                'status' => 'protocol_violation',
                'session_id' => $sessionId,
                'reason' => 'accepted_without_offer',
            ], 409);
        }

        $currency = $acts[\count($acts) - 1]->terms()['currency'] ?? 'EUR';

        return JsonEnvelope::noStore($this->transactionRecord->build(
            $parties,
            $acts,
            $acceptance,
            new RecordSubject(
                dealType: A2cnIdentity::DEAL_TYPES[0],
                currency: \is_string($currency) ? $currency : 'EUR',
                subject: $quote->quoteNumber,
                subjectReference: 'quote:' . $quote->quoteNumber,
            ),
            (new \DateTimeImmutable())->format(\DATE_ATOM),
        ));
    }
}
