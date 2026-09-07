<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Check;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use Override;

/**
 * Does the counterparty's LATEST act describe the quote Shopware actually
 * recorded?
 *
 * Acts are evidence, never a second input path: the engine reads the Shopware
 * snapshot, and an act that disagrees with it is a desync or a forgery attempt,
 * so we refuse to counter-sign into that chain.
 *
 * Only the most recent counterparty act is checked, deliberately — this is
 * not an oversight to "fix" back into a loop over the whole chain. Older acts
 * describe SUPERSEDED states that legitimately differ after any structural
 * edit, and each is already evidenced by its own hash. Checking all of them
 * meant that the moment a human edited a line after an escalation — this
 * module's headline scenario, see OfferVisibleStateSubscriber — an older
 * buyer act mismatched, `act_terms_mismatch` fired, no act was ever emitted
 * for that quote again, and the audit log blamed the buyer for the merchant's
 * own edit, on every subsequent observation, forever. The latest act is the
 * ask we are answering, so checking it preserves the desync-and-forgery
 * detection without permanently poisoning a quote.
 *
 * Scoped to STRUCTURE — line identity and quantity. Prices are deliberately
 * not compared: the buyer's ask and our offer legitimately differ, and the two
 * sides quote in different price spaces on a gross channel.
 * ponytail: structural check only. Add a price comparison if a desync ever gets
 * past this and the price spaces have been unified first.
 */
final readonly class BuyerTermsCheck implements EvidenceCheckInterface
{
    #[Override]
    public function check(
        ActChain $chain,
        QuoteSnapshot $snapshot,
        string $sellerDid,
        \DateTimeImmutable $at,
    ): ?ProtocolViolation {
        $recorded = [];
        foreach ($snapshot->content->lines as $line) {
            /** @var QuoteLineSnapshot $line */
            $recorded[$line->identity->lineItemId] = $line->quantity;
        }

        $buyerActs = $chain->buyerActs($sellerDid);
        $latest = $buyerActs[\count($buyerActs) - 1] ?? null;

        return $latest === null ? null : $this->crossCheck($latest, $recorded, $at);
    }

    /**
     * @param array<string, int> $recorded
     */
    private function crossCheck(Act $act, array $recorded, \DateTimeImmutable $at): ?ProtocolViolation
    {
        foreach ($act->lineQuantities() as $id => $quantity) {
            if (!\array_key_exists($id, $recorded)) {
                return $this->violation(
                    $act,
                    $at,
                    \sprintf('act references line %s, which the quote does not have', $id),
                );
            }

            if ($recorded[$id] !== $quantity) {
                return $this->violation(
                    $act,
                    $at,
                    \sprintf('act claims quantity %d for line %s, quote records %d', $quantity, $id, $recorded[$id]),
                );
            }
        }

        return null;
    }

    private function violation(Act $act, \DateTimeImmutable $at, string $description): ProtocolViolation
    {
        return new ProtocolViolation(
            timestamp: $at->format(\DATE_ATOM),
            violationType: 'act_terms_mismatch',
            messageId: $act->messageId(),
            description: $description,
        );
    }
}
