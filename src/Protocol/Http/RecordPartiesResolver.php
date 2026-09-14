<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey;
use MerchantQuoteAgentPlugin\Protocol\Mandate\SellerMandateFactory;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParties;
use MerchantQuoteAgentPlugin\Protocol\Record\RecordParty;

/**
 * Who the two parties on a record are, read off the chain and this
 * installation's own identity — split out of RecordResponder, which would
 * otherwise carry both this identity-resolution logic and the record-shaping
 * logic in one class and trip this repo's per-class cyclomatic-complexity
 * gate. The seam is the same one Record\OfferSelection and Record\OptionalAct
 * already use: a question worth asking once, on its own.
 */
final readonly class RecordPartiesResolver
{
    public function __construct(
        private A2cnIdentityResolver $identities,
    ) {}

    /** @param list<Act> $acts */
    public function resolve(array $acts, QuoteTerminalState $quote): RecordParties
    {
        try {
            $identity = $this->identities->forSalesChannel($quote->salesChannelId);
        } catch (MissingSigningKey|\Doctrine\DBAL\Exception) {
            // Neither a missing signing key nor a database read failure
            // should fail the whole records response — the record is still
            // served, only with the responder's identity fields left blank.
            $identity = null;
        }

        $responder = self::responder($identity);

        return new RecordParties(self::initiator($acts, $responder->did, $quote->buyerOrganizationName), $responder);
    }

    /**
     * One null check rather than one per field: an installation either knows
     * who it is or it does not, and a record that named three of our four
     * identity fields would be describing nobody.
     */
    private static function responder(?A2cnIdentity $identity): RecordParty
    {
        if ($identity === null) {
            return new RecordParty('', '', '', '', '');
        }

        return new RecordParty(
            organizationName: $identity->organizationName,
            did: $identity->did,
            agentId: $identity->agentId,
            verificationMethod: $identity->verificationMethod,
            // Ours is real and published, signed, at the well-known mandate
            // URL — so this one we may name.
            mandateType: SellerMandateFactory::MANDATE_TYPE,
        );
    }

    /** @param list<Act> $acts */
    private static function initiator(array $acts, string $responderDid, string $buyerOrganizationName): RecordParty
    {
        // The counterparty, taken from the first act we did not sign.
        $theirs = null;
        foreach ($acts as $act) {
            if ($act->senderDid() === $responderDid) {
                continue;
            }

            $theirs = $act;

            break;
        }

        return new RecordParty(
            // Shopware's own record of who this account is, not the act's
            // self-declaration: the wire act carries no organization at all, and
            // the customer row is who they are to us contractually.
            organizationName: $buyerOrganizationName,
            did: $theirs?->senderDid() ?? '',
            agentId: $theirs?->senderAgentId() ?? '',
            verificationMethod: $theirs?->verificationMethod() ?? '',
            // Empty on purpose. A2CN v0.2 gives a counterparty no way to hand
            // us a mandate — the act envelope carries none and the message
            // route asks for none — so we have never seen one and must not
            // imply otherwise. See RecordParty::$mandateType.
            mandateType: '',
        );
    }
}
