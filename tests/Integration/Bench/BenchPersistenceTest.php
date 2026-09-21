<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

/**
 * The one property the whole bench rests on: what it writes is still there
 * afterwards.
 *
 * IntegrationTestCase rolls every write back by design. A bench built on it
 * would produce no decision records at all, and the failure would be silent —
 * the run would pass, the admin would show nothing, and the obvious conclusion
 * would be that the grouping is broken rather than that the rows were never
 * committed.
 */
final class BenchPersistenceTest extends BenchTestCase
{
    public function testAWriteSurvivesTheTestThatMadeIt(): void
    {
        $connection = self::connection(static::getContainer());
        $marker = 'bench-persistence-' . bin2hex(random_bytes(8));

        $connection->executeStatement('INSERT INTO merchant_quote_agent_decision (id, quote_id, error_class, created_at)
             VALUES (UNHEX(:id), UNHEX(:quote), :marker, NOW())', [
            'id' => bin2hex(random_bytes(16)),
            'quote' => bin2hex(random_bytes(16)),
            'marker' => $marker,
        ]);

        // A fresh connection, so this cannot be read out of an open transaction.
        $found = self::freshConnection()
            ->fetchOne('SELECT COUNT(*) FROM merchant_quote_agent_decision WHERE error_class = :marker', [
                'marker' => $marker,
            ]);

        self::assertSame(1, (int) $found, 'The bench must leave its rows behind.');

        self::freshConnection()
            ->executeStatement('DELETE FROM merchant_quote_agent_decision WHERE error_class = :marker', [
                'marker' => $marker,
            ]);
    }
}
