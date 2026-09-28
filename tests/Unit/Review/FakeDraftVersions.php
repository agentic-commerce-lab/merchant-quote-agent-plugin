<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Review;

use MerchantQuoteAgentPlugin\Bridge\QuoteDraftVersionsInterface;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Tests\Unit\Servicing\FakeQuoteGateway;

final class FakeDraftVersions implements QuoteDraftVersionsInterface
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $merged = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> lockLive() and merge() in call order, beside what a test's transaction adds */
    public array $events = [];

    /** @var list<string> version ids exists() denies, as if their rows were gone */
    public array $missing = [];

    public ?\Closure $onCreate = null;

    public function __construct(
        public readonly FakeQuoteGateway $draft,
    ) {}

    #[\Override]
    public function create(string $quoteId): string
    {
        $this->onCreate && ($this->onCreate)();
        $id = sprintf('0190aaaa00007000800000000000%04d', \count($this->created) + 1);
        $this->created[] = $id;

        return $id;
    }

    #[\Override]
    public function exists(string $quoteId, string $versionId): bool
    {
        return !\in_array($versionId, $this->missing, strict: true);
    }

    #[\Override]
    public function gateway(string $versionId): QuoteGatewayInterface
    {
        return $this->draft;
    }

    #[\Override]
    public function lockLive(string $quoteId): void
    {
        $this->events[] = 'lock';
    }

    #[\Override]
    public function merge(string $versionId): void
    {
        $this->merged[] = $versionId;
        $this->events[] = 'merge';
    }

    #[\Override]
    public function delete(string $quoteId, string $versionId): void
    {
        $this->deleted[] = $versionId;
    }
}
