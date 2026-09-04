<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Protocol\Http;

use MerchantQuoteAgentPlugin\Protocol\Act\Act;
use MerchantQuoteAgentPlugin\Protocol\Crypto\SessionId;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteStateUnavailable;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalState;
use MerchantQuoteAgentPlugin\Protocol\Http\QuoteTerminalStateReader;
use MerchantQuoteAgentPlugin\Protocol\Store\ActRecord;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\InMemoryActStore;
use MerchantQuoteAgentPlugin\Tests\Unit\Protocol\ProtocolFixtures;

/**
 * Fixture builders shared by the records/act-chain HTTP tests. Split out of
 * A2cnRecordsControllerTest to keep that class's own method count under the
 * lint gate — these build test data, they do not make assertions.
 */
final class RecordsControllerFixtures
{
    public const QUOTE_ID = '11111111111111111111111111111111';

    private function __construct() {}

    /** @param array<int, string> $types */
    public static function storeWith(array $types): InMemoryActStore
    {
        $store = new InMemoryActStore();
        $session = SessionId::forQuote(self::QUOTE_ID);
        foreach ($types as $sequence => $type) {
            $raw = ProtocolFixtures::act(
                $sequence,
                $session,
                $type === 'counteroffer' ? ProtocolFixtures::SELLER : ProtocolFixtures::BUYER,
                $type,
            );
            $act = Act::fromArray($raw);
            \assert($act !== null, description: 'ProtocolFixtures::act() must always be readable as an Act.');
            $store->append(new ActRecord($session, self::QUOTE_ID, $sequence, $act));
        }

        return $store;
    }

    public static function live(): QuoteTerminalState
    {
        return new QuoteTerminalState('replied', false, 'Q-1001', 'sales-channel', null);
    }

    public static function terminal(): QuoteTerminalState
    {
        return new QuoteTerminalState('declined', false, 'Q-1001', 'sales-channel', null);
    }

    public static function accepted(Act $acceptance): QuoteTerminalState
    {
        return new QuoteTerminalState('replied', false, 'Q-1001', 'sales-channel', $acceptance);
    }

    /**
     * A reader double standing in for a gateway failure: it THROWS
     * QuoteStateUnavailable, distinct from returning null for "no such
     * quote" — see QuoteTerminalStateReader's own docblock for why the split
     * matters.
     */
    public static function throwingReader(): QuoteTerminalStateReader
    {
        return new class extends QuoteTerminalStateReader {
            public function __construct() {}

            public function for(string $quoteId, \DateTimeImmutable $now): ?QuoteTerminalState
            {
                throw new QuoteStateUnavailable('boom');
            }
        };
    }

    /** @return array<string, mixed> */
    public static function decode(string|false $content): array
    {
        \assert(\is_string($content), description: 'A JsonResponse always has string content.');
        $decoded = json_decode($content, associative: true);
        \assert(\is_array($decoded), description: 'Every records/acts response body is a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
