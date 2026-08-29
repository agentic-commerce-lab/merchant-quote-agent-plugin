<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Negotiation;

use Psr\Log\AbstractLogger;

/** A PSR-3 logger that keeps what it was told, so a test can read the pass event. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    #[\Override]
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return array<array-key, mixed>|null the context of the first record whose message contains $needle */
    public function contextOf(string $needle): ?array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record['context'];
            }
        }

        return null;
    }
}
