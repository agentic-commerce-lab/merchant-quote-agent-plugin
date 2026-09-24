<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;

/**
 * One decision record as one line of the anonymized export.
 *
 * An ALLOWLIST, not a redactor, and that is the whole design. This table has
 * gained six columns in three weeks from four unrelated issues, none of which
 * had this export in view; under a denylist every one of them would have
 * shipped straight into a file a merchant had been told was anonymized, and
 * silently. Here a new column simply does not appear -- and
 * ExportFieldCoverageTest fails until someone says which of the five lists
 * below it belongs to, so it cannot be decided by omission either.
 *
 * FREE_TEXT covers more than the reply. `rawProposal` is json_encode() of the
 * model's answer, written by a model whose prompt carried the buyer's message
 * verbatim, so it can repeat a name, an address or a phone number the buyer
 * typed. `violations` holds the model's own escalation sentence on one path
 * (OfferProposer passes $response->escalationReason straight through) and
 * machine strings on the others, and export time cannot tell them apart, so
 * the column follows its worst path -- the analytic signal is in
 * `escalationReason`, an enum, which is always exported.
 *
 * `historyReads.rounds[].result` is NOT free text under a flag: it is
 * HistoryRequestResolver's rendered block of past quote numbers, order
 * numbers, product labels and money for one named account, and there is no
 * reading of "anonymized export" under which it leaves. Only the `kind` of
 * each read survives, which is what answers the question the export exists to
 * ask -- does letting the agent read history change what it decides.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The class-level count sums of() plus its three small reshaping helpers,
 * each of which is its own field-by-field null/flag check and stays well
 * under the per-method threshold on its own. Splitting the helpers into
 * further classes would scatter one mapper's coverage across files for no
 * reader's benefit -- ExportFieldCoverageTest already pins the whole shape.
 */
final class AnonymizedDecision
{
    /**
     * Shop-local ids: the grouping is the value, the identity is the risk, so
     * they are salted rather than dropped. Renamed on the way out because the
     * exported string is a pseudonym, not the id the name would promise.
     *
     * @var array<string, string> entity property => export key
     */
    public const PSEUDONYMIZED = [
        'id' => 'id',
        'quoteId' => 'quote',
        'customerId' => 'customer',
        'salesChannelId' => 'salesChannel',
        'revisionVersionId' => 'revision',
        'strategyVersionId' => 'strategyVersion',
    ];

    /**
     * Numbers, enums, hashes, timestamps and closed vocabularies. `writes` is
     * here because OfferApplier builds it from method names, never from data.
     *
     * @var list<string>
     */
    public const VERBATIM = [
        'currencyIso',
        'triggerReason',
        'attempt',
        'revisionUpdatedAt',
        'band',
        'outcome',
        'escalationReason',
        'strategyAssignmentSource',
        'discountPercentGranted',
        'maxDiscountPercent',
        'totalNetBefore',
        'totalNetAfter',
        'model',
        'modelHost',
        'extractPromptHash',
        'negotiatePromptHash',
        'replyPromptHash',
        'promptTokens',
        'completionTokens',
        'modelLatencyMs',
        'durationMs',
        'authorized',
        'verified',
        'errorClass',
        'terminalState',
        'terminalAt',
        'resolvedAt',
        'resolvedState',
        'writes',
        'reviewStatus',
        'reviewedAt',
        'sentChanges',
        'feedbackReasons',
        'feedbackAt',
    ];

    /** JSON columns exported with their free text or business data removed. @var list<string> */
    public const RESHAPED = ['interpretedAsks', 'historyReads', 'errorChain'];

    /** Exported only under --include-comments (the admin Export button includes them by default). @var list<string> */
    public const FREE_TEXT = ['rawProposal', 'buyerAsk', 'replyToBuyer', 'violations', 'sentReply', 'feedbackComment'];

    /**
     * A document number a human reads, printed on the buyer's quote. Nothing
     * cross-shop needs it. And two internal working columns: a DAL version id
     * and the staleness fingerprint, which carries line ids and comment
     * timestamps.
     *
     * @var list<string>
     */
    public const DROPPED = ['quoteNumber', 'draftVersionId', 'reviewFingerprint'];

    private function __construct() {}

    /** @return array<string, mixed> */
    public static function of(QuoteDecisionRecord $record, ExportPseudonym $pseudonym, bool $freeText): array
    {
        $row = [
            'id' => $pseudonym->of($record->id),
            'quote' => $pseudonym->of($record->quoteId),
            'customer' => $pseudonym->of($record->customerId),
            'salesChannel' => $pseudonym->of($record->salesChannelId),
            'revision' => $pseudonym->of($record->revisionVersionId),
            'strategyVersion' => $pseudonym->of($record->strategyVersionId),
            'createdAt' => self::at($record->getCreatedAt()),
            'currencyIso' => $record->currencyIso,
            'triggerReason' => $record->triggerReason,
            'attempt' => $record->attempt,
            'revisionUpdatedAt' => self::at($record->revisionUpdatedAt),
            'band' => $record->band,
            'outcome' => $record->outcome,
            'escalationReason' => $record->escalationReason,
            'strategyAssignmentSource' => $record->strategyAssignmentSource,
            'discountPercentGranted' => $record->discountPercentGranted,
            'maxDiscountPercent' => $record->maxDiscountPercent,
            'totalNetBefore' => $record->totalNetBefore,
            'totalNetAfter' => $record->totalNetAfter,
            'model' => $record->model,
            'modelHost' => $record->modelHost,
            'extractPromptHash' => $record->extractPromptHash,
            'negotiatePromptHash' => $record->negotiatePromptHash,
            'replyPromptHash' => $record->replyPromptHash,
            'promptTokens' => $record->promptTokens,
            'completionTokens' => $record->completionTokens,
            'modelLatencyMs' => $record->modelLatencyMs,
            'durationMs' => $record->durationMs,
            'authorized' => $record->authorized,
            'verified' => $record->verified,
            'errorClass' => $record->errorClass,
            'terminalState' => $record->terminalState,
            'terminalAt' => self::at($record->terminalAt),
            'resolvedAt' => self::at($record->resolvedAt),
            'resolvedState' => $record->resolvedState,
            'writes' => $record->writes,
            'reviewStatus' => $record->reviewStatus,
            'reviewedAt' => self::at($record->reviewedAt),
            'sentChanges' => $record->sentChanges,
            'feedbackReasons' => $record->feedbackReasons,
            'feedbackAt' => self::at($record->feedbackAt),
            'interpretedAsks' => self::asks($record->interpretedAsks, $freeText),
            'historyReads' => self::history($record->historyReads),
            'errorChain' => self::errors($record->errorChain, $freeText),
        ];

        if (!$freeText) {
            return $row;
        }

        return [
            ...$row,
            'rawProposal' => $record->rawProposal,
            'buyerAsk' => $record->buyerAsk,
            'replyToBuyer' => $record->replyToBuyer,
            'violations' => $record->violations,
            'sentReply' => $record->sentReply,
            'feedbackComment' => $record->feedbackComment,
        ];
    }

    private static function at(?\DateTimeInterface $at): ?string
    {
        return $at?->format(\DateTimeInterface::ATOM);
    }

    /**
     * Every typed ask survives -- prices, quantities, delivery, payment --
     * because that structure is what the export is for. The two
     * lists of sentences the extraction model wrote while reading the buyer's
     * message do not.
     *
     * @param array<string, mixed>|null $asks
     *
     * @return array<string, mixed>|null
     */
    public static function asks(?array $asks, bool $freeText): ?array
    {
        if ($asks === null || $freeText) {
            return $asks;
        }

        unset($asks['clarificationQuestions'], $asks['humanReviewRequests']);

        return $asks;
    }

    /**
     * The summary half is counts and totals -- the account's shape, not its
     * identity -- and survives whole. Each round keeps only what was asked.
     *
     * @param array<string, mixed>|null $reads
     *
     * @return array<string, mixed>|null
     */
    public static function history(?array $reads): ?array
    {
        if ($reads === null) {
            return null;
        }

        $rounds = $reads['rounds'] ?? [];
        $reads['rounds'] = array_values(array_map(
            static fn(mixed $round): mixed => \is_array($round) ? $round['kind'] ?? null : null,
            \is_array($rounds) ? $rounds : [],
        ));

        return $reads;
    }

    /**
     * The class and the file:line are ours. The message is whatever the
     * throwing code chose to put in it, which on the model path can be a
     * provider response body quoting the prompt.
     *
     * @param list<array<string, string>>|null $chain
     *
     * @return list<array<string, string>>|null
     */
    public static function errors(?array $chain, bool $freeText): ?array
    {
        if ($chain === null || $freeText) {
            return $chain;
        }

        return array_values(array_map(static fn(array $link): array => [
            'class' => $link['class'] ?? '',
            'at' => $link['at'] ?? '',
        ], $chain));
    }
}
