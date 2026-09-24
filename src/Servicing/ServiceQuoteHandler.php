<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException;
use MerchantQuoteAgentPlugin\Negotiation\ClarificationMarker;
use MerchantQuoteAgentPlugin\Negotiation\QuoteBaseline;
use MerchantQuoteAgentPlugin\Servicing\Data\PassContext;
use MerchantQuoteAgentPlugin\Servicing\Data\ServiceQuoteMessage;
use MerchantQuoteAgentPlugin\Servicing\Data\ServicingTriggerReason;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Claims a quote, decides whether anything actually needs servicing, and hands
 * it to #18. Never runs in the triggering request — LLM latency is seconds.
 */
#[AsMessageHandler]
final readonly class ServiceQuoteHandler
{
    /**
     * A crash budget, not a retry budget. Messenger's `max_retries: 3` is
     * driven by a RedeliveryStamp that SendFailedMessageForRetryListener adds on
     * WorkerMessageFailedEvent — i.e. only when a handler THROWS. A segfault
     * (exit 139, `addProduct` on a variant product, #3) emits no event and
     * accrues no stamp, so the doctrine transport reclaims the row after
     * redeliver_timeout (3600s) and redelivers with retry count 0, hourly,
     * forever. This counter is what bounds that, and it is 4 so that a crash
     * budget and a throw budget are the same size.
     */
    public const MAX_ATTEMPTS = 4;

    public const ATTEMPTS_KEY = 'merchant_quote_agent_attempts';

    /**
     * Flat, not Messenger's default backoff. The default multiplies by 2 with
     * `max_delay: 0` (unbounded — verified against this shop's
     * `debug:config framework messenger`), so a quote that stays busy backs off
     * to hours. Lock contention resolves on the scale of one servicing pass, so
     * a fixed delay just above it is the honest wait.
     *
     * Public because ObserveQuoteHandler contends for the very same per-quote
     * lock and must wait the same amount — two numbers would drift.
     */
    public const BUSY_RETRY_DELAY_MS = 5000;

    public function __construct(
        private QuoteServicingLock $locks,
        private LoggerInterface $logger,
        private ServicingPreflight $preflight,
        private ?QuoteGatewayInterface $gateway = null,
        private ?QuoteServicingPipelineInterface $pipeline = null,
    ) {}

    /**
     * @throws \Throwable a pipeline failure, rethrown after clearing the
     *                     crash-budget counter — the pipeline's exception
     *                     surface is #18's, not ours
     */
    public function __invoke(ServiceQuoteMessage $message): void
    {
        $gateway = $this->gateway;

        if ($gateway === null) {
            // Not a silent no-op, and not a silent ack either: QuoteGatewayFactory
            // returns null when SwagCommercial is absent or unlicensed (#3), so
            // the quote WAS asked for and never got an answer. #4 asks for a loud
            // log line AND a parked message — Unrecoverable sends it straight to
            // the `failed` transport instead of burning three retries first, and
            // re-licensing the shop is what makes replaying it worthwhile.
            $this->logger->warning('Quote queued for servicing but the SwagCommercial gateway is unavailable.', [
                'quoteId' => $message->quoteId,
                'reason' => $message->reason,
            ]);

            throw new UnrecoverableMessageHandlingException(sprintf(
                'Quote %s cannot be serviced: the SwagCommercial gateway is unavailable.',
                $message->quoteId,
            ));
        }

        $lock = $this->locks->for($message->quoteId);

        if (!$lock->acquire()) {
            // Deliberately not a silent return: another worker holds this
            // quote, and after it finishes the fingerprint may STILL differ —
            // a buyer comment that landed mid-pass. Dropping the message here
            // would drop that ask.
            throw new RecoverableMessageHandlingException(
                sprintf('Quote %s is being serviced by another worker.', $message->quoteId),
                retryDelay: self::BUSY_RETRY_DELAY_MS,
            );
        }

        try {
            $this->servicePass($gateway, $message);
        } catch (QuoteNotFoundException $e) {
            // A quote deleted between trigger and handling is not a failure
            // worth retrying. Every other throwable propagates to Messenger.
            $this->logger->warning('Quote queued for servicing no longer exists.', [
                'quoteId' => $message->quoteId,
                'exception' => $e,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws QuoteNotFoundException
     * @throws \Throwable rethrown from the pipeline after clearing the crash-budget
     *                     counter; the pipeline's exception surface is #18's, not ours
     */
    private function servicePass(QuoteGatewayInterface $gateway, ServiceQuoteMessage $message): void
    {
        $snapshot = $gateway->fetchSnapshot($message->quoteId);

        // Resolved before the preflight, not just before claimAttempt(): the
        // rule the old comment here stated — a stale enum value must throw
        // before anything is mutated — is served strictly better this way,
        // since the preflight can write a marker, a comment and a notification
        // on the strength of a trigger nothing can name. The counter is read
        // rather than claimed; a refused pass claims nothing.
        $context = new PassContext(ServicingTriggerReason::from($message->reason), self::attemptsOn($snapshot));
        $settings = $this->preflight->check($gateway, $snapshot, $context);

        if ($settings === null) {
            // Returns BEFORE stamping, like every other refusal: the quote was
            // not serviced — the agent is paused, its configuration is broken,
            // or the quote is in a state SwagCommercial will not edit — and a
            // stamp would suppress the next real trigger once that changes.
            return;
        }

        $fingerprint = ServicingFingerprint::of($snapshot);

        if ($fingerprint === ServicingFingerprint::stamped($snapshot->lifecycle->customFields)) {
            $this->logger->debug('Nothing has happened on this quote since the last servicing pass.', [
                'quoteId' => $message->quoteId,
                'fingerprint' => $fingerprint,
            ]);

            return;
        }

        $pipeline = $this->pipeline;

        if ($pipeline === null) {
            // Returns BEFORE stamping: nothing was serviced, so claiming it was
            // would suppress the next real trigger.
            $this->logger->warning('Quote claimed for servicing but no servicing pipeline is registered.', [
                'quoteId' => $message->quoteId,
            ]);

            return;
        }

        $this->claimAttempt($gateway, $message, $snapshot);

        try {
            $outcome = $pipeline->service($snapshot, $gateway, $settings, $context);
        } catch (\Throwable $e) {
            // The process survived, so Messenger's RedeliveryStamp already
            // bounds this failure via retry. The quote-side counter exists only
            // for a delivery that vanishes WITHOUT one (a segfaulted worker) —
            // clearing it here keeps that budget for its real purpose instead
            // of letting four transient LLM failures permanently park the quote.
            $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
                self::ATTEMPTS_KEY => null,
            ]));

            throw $e;
        }

        // The fresh read supplies the STATE only. The comment components come
        // from $snapshot — the buyer input this pass actually consumed.
        // Stamping of($after) wholesale would claim credit for a buyer comment
        // that landed DURING the pass and silently drop it: see the spec's
        // "The stamp describes what was consumed, not what exists afterwards".
        $after = $gateway->fetchSnapshot($message->quoteId);

        // The fingerprint is stamped whatever the outcome: the ask WAS handled,
        // even when the answer was to escalate. Whether the escalation marker
        // is ALSO cleared is QuoteEscalator's rule — it owns the key, and a
        // pass that escalated must keep the marker it just wrote.
        //
        // Two markers are released here, each by its owner's rule: a pass that
        // escalated keeps the escalation marker it just wrote, and a pass that
        // asked a clarification keeps that one.
        //
        // The disclosure marker is the one here that is never released: the two
        // above record a state the quote can leave, this one records that an
        // agent acted on it, which stays true. It has its own outcome gate
        // (not every completed pass is an agent acting - see AgentDisclosure),
        // and it is spread last so a future release fragment cannot null it by
        // accident.
        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
            ServicingFingerprint::MARKER_KEY => ServicingFingerprint::stamp(
                $snapshot,
                $after->lifecycle->stateTechnicalName,
            ),
            self::ATTEMPTS_KEY => null,
            ...QuoteEscalator::releaseFor($outcome),
            ...ClarificationMarker::releaseFor($outcome),
            ...AgentDisclosure::stampFor($outcome, $settings->draftMode),
        ]));
    }

    /** @return int the attempt count already committed against this quote, or 0 for a fresh one */
    private static function attemptsOn(QuoteSnapshot $snapshot): int
    {
        $attempts = $snapshot->lifecycle->customFields[self::ATTEMPTS_KEY] ?? 0;

        return \is_int($attempts) ? $attempts : 0;
    }

    private function claimAttempt(
        QuoteGatewayInterface $gateway,
        ServiceQuoteMessage $message,
        QuoteSnapshot $snapshot,
    ): void {
        $attempts = self::attemptsOn($snapshot);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->logger->error('Servicing this quote has failed {attempts} times without a thrown error, which means '
            . 'it is killing the worker process. Parking the message. Clear the "{key}" custom '
            . 'field on the quote to let the agent try again.', [
                'attempts' => $attempts,
                'key' => self::ATTEMPTS_KEY,
                'quoteId' => $message->quoteId,
            ]);

            throw new UnrecoverableMessageHandlingException(sprintf(
                'Quote %s exceeded the servicing crash budget.',
                $message->quoteId,
            ));
        }

        // Committed before the pipeline runs, so it survives a process death
        // during it. This ordering IS the mechanism.
        //
        // The baseline (#49) rides along for the same reason. Captured here
        // rather than at the first price write because ServicingFingerprint's
        // marker is stamped whatever the outcome, so a quote marked serviced
        // with no baseline must mean exactly one thing — it predates this
        // deploy — and not "a pass ran but wrote nothing". This is also the
        // quote as the agent first found it, which is the spec's own
        // definition and strictly earlier than any pre-write read.
        //
        // #54: the same write also appends a line added since the stamp, at
        // the price it has at pass start, which is before this pass concedes
        // anything.
        $gateway->updateQuote($message->quoteId, new QuoteUpdate(customFields: [
            self::ATTEMPTS_KEY => $attempts + 1,
            ...QuoteBaseline::stampOrExtend($snapshot),
        ]));
    }
}
