<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Audit\DecisionRecorder;
use MerchantQuoteAgentPlugin\Audit\QuoteDecisionRecord;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettings;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsSource;
use MerchantQuoteAgentPlugin\Negotiation\PromptComposer;
use MerchantQuoteAgentPlugin\Negotiation\ReplyComposer;
use MerchantQuoteAgentPlugin\Review\DraftReply;
use MerchantQuoteAgentPlugin\Review\InvalidReviewRequest;
use MerchantQuoteAgentPlugin\Review\PendingDraft;
use MerchantQuoteAgentPlugin\Tests\Unit\Audit\FakeDecisionWriter;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DraftReplyTest extends TestCase
{
    public function testTheReplyIsRedraftedAgainstTheEditedTotal(): void
    {
        $live = NegotiationFixture::snapshot(totalNet: 1000.0);
        $after = NegotiationFixture::snapshot(totalNet: 920.0);
        [$client] = ScriptedClient::spy(['not a usable reply']);

        $text = self::reply($client, NegotiationFixture::settings())->compose(self::pending($live, $after), $after);

        self::assertNotNull($text);
        self::assertStringContainsString('8', $text);
    }

    public function testAClarificationIsNotRedrafted(): void
    {
        $live = NegotiationFixture::snapshot();
        [$client] = ScriptedClient::spy([]);

        self::assertNull(
            self::reply($client, NegotiationFixture::settings())
                ->compose(new PendingDraft(self::record(), $live, null, false), $live),
        );
    }

    public function testAPriceIncreaseIsRefusedEvenWhenSettingsAreDisabled(): void
    {
        $live = NegotiationFixture::snapshot(totalNet: 1000.0);
        $after = NegotiationFixture::snapshot(totalNet: 1100.0);
        [$client] = ScriptedClient::spy([]);

        $this->expectException(InvalidReviewRequest::class);

        self::reply($client, null)->compose(self::pending($live, $after), $after);
    }

    private static function reply(
        \MerchantQuoteAgentPlugin\Negotiation\ModelPlatform $client,
        ?QuoteAgentSettings $settings,
    ): DraftReply {
        $source = new class($settings) implements QuoteAgentSettingsSource {
            public function __construct(
                private readonly ?QuoteAgentSettings $settings,
            ) {}

            public function forSalesChannel(?string $salesChannelId): ?QuoteAgentSettings
            {
                return $this->settings;
            }
        };

        return new DraftReply(
            new ReplyComposer(
                $client,
                new PromptComposer('EXTRACT', 'NEGOTIATE', 'REPLY {{tone}}'),
                new NullLogger(),
                new DecisionRecorder(new FakeDecisionWriter()),
            ),
            $source,
            new NullLogger(),
        );
    }

    private static function pending(
        \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $live,
        \MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot $after,
    ): PendingDraft {
        return new PendingDraft(self::record(), $live, new FakeQuoteGateway([$after]), false);
    }

    private static function record(): QuoteDecisionRecord
    {
        $record = new QuoteDecisionRecord();
        $record->id = 'rec';
        $record->quoteId = 'q1';

        return $record;
    }
}
