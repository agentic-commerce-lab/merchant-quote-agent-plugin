<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Servicing;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Servicing\EscalationNotice;
use MerchantQuoteAgentPlugin\Servicing\QuoteAgentEscalatedEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\MailAware;

/**
 * Before this, the event fed the Administration's "available data" panel via
 * getAvailableData() but delivered nothing at runtime: MailStorer and
 * ScalarValuesStorer both gate on `instanceof` checks the event failed, so
 * `SendMailAction` threw MailEventConfigurationException on ANY flow wired to
 * this event — the plugin's own default one or a merchant's hand-built one —
 * and `{{ quoteNumber }}`/`{{ escalationReason }}` always rendered empty. See
 * the class docblock for how MailStorer/SendMailAction/ScalarValuesStorer were
 * read to establish that, not assumed.
 */
final class QuoteAgentEscalatedEventTest extends TestCase
{
    private static function event(): QuoteAgentEscalatedEvent
    {
        $notice = new EscalationNotice(
            quoteId: 'q1',
            quoteNumber: '10001',
            salesChannelId: 'sc1',
            reason: QuoteEscalationReason::ProposalRejected,
        );

        return new QuoteAgentEscalatedEvent($notice, Context::createDefaultContext());
    }

    public function testItImplementsMailAwareSoSendMailActionDoesNotThrow(): void
    {
        self::assertInstanceOf(MailAware::class, self::event());
    }

    /**
     * Never read for the shipped flow's "admin" recipient type, but must
     * exist and not throw — MailStorer calls it for every MailAware event.
     */
    public function testGetMailStructIsEmptyRatherThanAGuessedRecipient(): void
    {
        self::assertSame([], self::event()->getMailStruct()->getRecipients());
    }

    public function testItImplementsScalarValuesAwareSoFlowDataIsPopulated(): void
    {
        self::assertInstanceOf(ScalarValuesAware::class, self::event());
    }

    public function testGetValuesCarriesWhatAMailBodyOrRuleConditionNeeds(): void
    {
        self::assertSame(
            [
                'quoteId' => 'q1',
                'quoteNumber' => '10001',
                'escalationReason' => 'proposal_rejected',
            ],
            self::event()->getValues(),
        );
    }

    /**
     * getAvailableData() only documents what the Administration shows as
     * available; getValues() is what a running flow actually gets. A key in
     * one and not the other is either a broken Twig variable or dead UI
     * documentation.
     */
    public function testGetAvailableDataStaysInStepWithGetValues(): void
    {
        $documented = array_keys(QuoteAgentEscalatedEvent::getAvailableData()->toArray());

        self::assertSame(['quoteId', 'quoteNumber', 'escalationReason'], $documented);
        self::assertSame($documented, array_keys(self::event()->getValues()));
    }

    public function testGetAvailableDataOnlyDeclaresScalarStrings(): void
    {
        foreach (QuoteAgentEscalatedEvent::getAvailableData()->toArray() as $entry) {
            self::assertSame(['type' => 'string'], $entry);
        }
    }
}
