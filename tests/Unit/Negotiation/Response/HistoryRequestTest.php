<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\Response;

use MerchantQuoteAgentPlugin\Negotiation\Response\HistoryRequestKind;
use MerchantQuoteAgentPlugin\Negotiation\Response\NegotiateResponse;
use MerchantQuoteAgentPlugin\Negotiation\Response\ResponseFormatFactory;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\NegotiationFixture;
use MerchantQuoteAgentPlugin\Tests\Unit\Negotiation\ScriptedClient;
use PHPUnit\Framework\TestCase;

/**
 * The model asks for history through its own answer, not through the OpenAI
 * `tools` API. This is the shape that makes that possible, and it deliberately
 * mirrors OfferTerms: a non-nullable nested object with nullable fields and a
 * default instance. A nullable nested OBJECT is unproven against the providers
 * this plugin supports; that shape is not.
 */
final class HistoryRequestTest extends TestCase
{
    public function testAResponseWithNoRequestStillMaps(): void
    {
        // Backward compatibility: every existing negotiate answer, and every
        // fixture in this suite, omits the field entirely.
        $response = self::read('{"action":"offer","message":"5% for you.","terms":{"discountPercent":5}}');

        self::assertFalse($response->wantsHistory());
        self::assertNull($response->historyRequest->kind);
    }

    public function testAKindIsReadOffTheAnswer(): void
    {
        $response = self::read('{"action":"offer","message":"","historyRequest":{"kind":"orders"}}');

        self::assertTrue($response->wantsHistory());
        self::assertSame(HistoryRequestKind::Orders, $response->historyRequest->kind);
    }

    public function testAProductScopedRequestCarriesItsProduct(): void
    {
        $response = self::read(
            '{"action":"offer","message":"","historyRequest":{"kind":"product_purchases","productId":"prod-1"}}',
        );

        self::assertSame(HistoryRequestKind::ProductPurchases, $response->historyRequest->kind);
        self::assertSame('prod-1', $response->historyRequest->productId);
    }

    public function testTheSchemaOffersNoCustomerFieldAtAll(): void
    {
        // THE security assertion of this task. There must be nothing in the
        // model-visible surface that names a customer: the id is bound
        // server-side from the quote being serviced, and a customer argument
        // here would be a cross-company read one prompt injection away.
        $schema = (string) json_encode((new ResponseFormatFactory())->create(NegotiateResponse::class));

        self::assertStringNotContainsStringIgnoringCase('customer', $schema);
    }

    public function testTheSchemaPermitsOnlyTheThreeKnownKinds(): void
    {
        $schema = (string) json_encode((new ResponseFormatFactory())->create(NegotiateResponse::class));

        self::assertStringContainsString('quote_history', $schema);
        self::assertStringContainsString('orders', $schema);
        self::assertStringContainsString('product_purchases', $schema);
    }

    /**
     * `historyRequest.kind` is required (it has a default instance, not a
     * nullable field, so the parent schema marks it required) and its `enum`
     * is otherwise an absolute whitelist of the three kinds. Without `null`
     * in that list, a provider enforcing the schema strictly cannot accept
     * "I don't want history" at all -- every negotiate response would be
     * forced to request history, exhausting the round budget and escalating
     * every quote.
     */
    public function testTheKindEnumAdmitsNullSoDecliningHistoryIsLegal(): void
    {
        $schema = (new ResponseFormatFactory())->create(NegotiateResponse::class)['json_schema']['schema'];
        $kind = $schema['properties']['historyRequest']['properties']['kind'];

        self::assertContains(null, $kind['enum'] ?? [], 'the model must be able to legally decline history.');
    }

    private static function read(string $json): NegotiateResponse
    {
        return ScriptedClient::returning([$json])->object(
            NegotiationFixture::modelAccess(),
            'sys',
            'usr',
            NegotiateResponse::class,
        );
    }
}
