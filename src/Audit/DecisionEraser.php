<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Audit\Export\AnonymizedDecision;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;

/**
 * Removes one buyer from the decision records without removing the records.
 *
 * The table has no retention policy and no cleanup task: rows live until the
 * plugin is uninstalled with "remove all data". That was already true of
 * `reply_to_buyer` and the model's raw answers; #177 made it matter more by
 * storing the buyer's own comment verbatim, because a decision the agent
 * answered with silence can only be reviewed against the words it read.
 *
 * An erasure request is the case this exists for, and it is a request from a
 * person, not a side effect: nothing subscribes to customer deletion and
 * nothing runs on a schedule. A merchant answering such a request types the
 * command, and the run says how many rows it touched. Deleting the rows
 * outright would be the other reading of the same request, and is deliberately
 * not what this does -- what the agent decided, and under which policy, is the
 * merchant's own record of their business, and none of it needs a person in it.
 *
 * WHAT IS CLEARED is the classification the export already made. Its
 * FREE_TEXT list is the four columns that can carry the buyer's words, and its
 * three JSON reshapers are what the same question answers for a column that is
 * half structure and half prose -- so this asks AnonymizedDecision rather than
 * deciding a second time and drifting. The only difference is when the
 * question is asked: a merchant chooses per export, a person asks to be
 * forgotten once and permanently.
 * The trace follows the same split: its `content` is cleared and its `meta`
 * kept (2026-09-23 spec §3).
 */
final readonly class DecisionEraser implements DecisionEraserInterface
{
    public function __construct(
        private EntityRepository $decisions,
        private EntityRepository $traces,
    ) {}

    #[\Override]
    public function forget(string $customerId, ?Context $context = null): Erasure
    {
        $context ??= Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));

        $records = $this->decisions->search($criteria, $context)->getEntities();
        $payload = [];
        $quoteIds = [];

        foreach ($records as $record) {
            if (!$record instanceof QuoteDecisionRecord) {
                continue;
            }

            $payload[] = self::erased($record);
            $quoteIds[] = $record->quoteId;
        }

        // Read quote ids before decision rows lose their customer id. These
        // also find traces that never knew the customer directly.
        $traces = $this->forgetTraces($customerId, array_values(array_unique(array_filter($quoteIds))), $context);

        if ($payload !== []) {
            $this->decisions->update($payload, $context);
        }

        return new Erasure(\count($payload), $traces);
    }

    /**
     * `content` can carry the person; `meta` is the merchant's record and
     * stays. Fetch ids only because prompt content can be large.
     *
     * @param list<string> $quoteIds
     */
    private function forgetTraces(string $customerId, array $quoteIds, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new OrFilter([
            new EqualsFilter('customerId', $customerId),
            ...($quoteIds === [] ? [] : [new EqualsAnyFilter('quoteId', $quoteIds)]),
        ]));

        $ids = array_values(array_filter($this->traces->searchIds($criteria, $context)->getIds(), \is_string(...)));

        if ($ids === []) {
            return 0;
        }

        $this->traces->update(array_map(static fn(string $id): array => [
            'id' => $id,
            'content' => null,
            'customerId' => null,
        ], $ids), $context);

        return \count($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private static function erased(QuoteDecisionRecord $record): array
    {
        return [
            'id' => $record->id,
            // The buyer's own words, the agent's words to them, the model's raw
            // answer and the escalation prose -- AnonymizedDecision::FREE_TEXT,
            // the same four.
            'buyerAsk' => null,
            'replyToBuyer' => null,
            'rawProposal' => null,
            'violations' => null,
            // Structured asks stay: a price, a quantity and a band are the
            // merchant's own record of what they decided. The two lists of
            // sentences the extract model wrote are not structured at all.
            'interpretedAsks' => AnonymizedDecision::asks($record->interpretedAsks, freeText: false),
            // Which lookups were made, never what they returned: a round's
            // result quotes that account's past quotes and orders.
            'historyReads' => AnonymizedDecision::history($record->historyReads),
            // A provider error body can quote the prompt, and the prompt
            // carries the buyer's comment.
            'errorChain' => AnonymizedDecision::errors($record->errorChain, freeText: false),
            // Last, so the rows can still be found by this id until they are
            // rewritten, and never afterwards.
            'customerId' => null,
        ];
    }
}
