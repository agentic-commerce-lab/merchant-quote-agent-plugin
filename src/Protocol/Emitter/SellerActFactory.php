<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Emitter;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Act\ActChain;
use MerchantQuoteAgentPlugin\Protocol\Act\SignedView;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentity;
use MerchantQuoteAgentPlugin\Protocol\Identity\A2cnIdentityResolver;
use MerchantQuoteAgentPlugin\Protocol\Terms\TermsFactory;

/**
 * Builds the one act type this agent emits: a signed `counteroffer` at the next
 * sequence and round.
 *
 * The session id is DERIVED (SessionId::forQuote), never taken from the chain —
 * SessionIdCheck has already refused a chain that claims a different one, so
 * using the derived value here means the act cannot inherit a forged session.
 *
 * The wire act carries no `protocol_version`: that field is part of the SIGNED
 * object only (SignedView), and putting it on the wire would invite a verifier
 * to include it twice.
 */
final readonly class SellerActFactory
{
    private const MESSAGE_TYPE = 'counteroffer';

    public function __construct(
        private ActSigner $signer,
        private TermsFactory $terms,
        private A2cnIdentityResolver $identities,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws \MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount
     */
    public function terms(QuoteSnapshot $snapshot): array
    {
        return $this->terms->fromSnapshot($snapshot);
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $current
     */
    public function termsUnchanged(?array $previous, array $current): bool
    {
        return $this->terms->unchanged($previous, $current);
    }

    /**
     * @throws \MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey
     * @throws \Doctrine\DBAL\Exception
     */
    public function identityFor(QuoteSnapshot $snapshot): ?A2cnIdentity
    {
        $salesChannelId = $snapshot->identity->salesChannelId;

        return $salesChannelId === '' ? null : $this->identities->forSalesChannel($salesChannelId);
    }

    /**
     * @throws \MerchantQuoteAgentPlugin\Protocol\Terms\NonFiniteAmount
     * @throws \MerchantQuoteAgentPlugin\Protocol\Identity\MissingSigningKey
     * @throws UnbuildableAct
     */
    public function build(
        QuoteSnapshot $snapshot,
        ActChain $chain,
        A2cnIdentity $identity,
        \DateTimeImmutable $now,
    ): Act {
        $sessionId = SessionId::forQuote($snapshot->identity->quoteId);
        $sequence = $chain->nextSequence();
        $timestamp = $now->format(\DATE_ATOM);

        $wire = [
            'message_type' => self::MESSAGE_TYPE,
            'message_id' => $sessionId . ':' . $sequence,
            'session_id' => $sessionId,
            'round_number' => $chain->nextRound(),
            'sequence_number' => $sequence,
            'sender_did' => $identity->did,
            'sender_agent_id' => $identity->agentId,
            'sender_verification_method' => $identity->verificationMethod,
            'timestamp' => $timestamp,
            'terms' => $this->terms($snapshot),
        ];

        $inReplyTo = $chain->last()?->messageId();
        if ($inReplyTo !== null) {
            $wire['in_reply_to'] = $inReplyTo;
        }

        $expiresAt = $snapshot->lifecycle->expiresAt;
        if ($expiresAt !== null) {
            $wire['expires_at'] = $expiresAt->format(\DATE_ATOM);
        }

        // Sign the view of the act as it will actually stand, then attach the
        // proof: the hash covers exactly what a verifier recomputes.
        $unsigned = Act::fromArray($wire + ['protocol_act_hash' => '', 'protocol_act_signature' => '']);
        if ($unsigned === null) {
            throw new UnbuildableAct('Refusing to emit an act this plugin cannot read back.');
        }

        $signed = Act::fromArray(
            $wire + $this->signer->proofFor(SignedView::of($unsigned), $identity->verificationMethod),
        );
        if ($signed === null) {
            throw new UnbuildableAct('Refusing to emit an act this plugin cannot read back.');
        }

        return $signed;
    }
}
