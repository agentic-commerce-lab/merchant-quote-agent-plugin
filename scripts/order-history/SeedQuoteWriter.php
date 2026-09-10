<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Scripts\OrderHistory;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteTransition;
use Shopware\Core\Defaults;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/** Creates or resumes only quotes bearing this seeder's private marker. */
final readonly class SeedQuoteWriter
{
    public function __construct(
        private SeedServices $services,
        private SeedData $data,
    ) {}

    /**
     * @return array{id: string, state: string, date: string}
     * @throws \Doctrine\DBAL\Exception
     * @throws \TypeError On malformed database rows.
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function quote(SalesChannelContext $context, ?string $productId, int $slot, \DateTimeImmutable $date): array
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            throw new \RuntimeException('Seeding requires an authenticated customer.');
        }
        $marker = SeedData::marker($customer->getId(), $slot);
        $existing = $this->data->quote($marker, $customer->getId());
        if ($existing !== null) {
            return $existing;
        }
        if ($productId === null) {
            throw new \RuntimeException('A new seed quote requires a validated product.');
        }
        $quote = $this->services->buyer()->requestQuote(
            $context,
            [['product_id' => $productId, 'quantity' => 1 + (($slot - 1) % 4)]],
            null,
        );
        $storedDate = $date->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $this->services->repository('quote.repository')->update([[
            'id' => $quote->id,
            'versionId' => Defaults::LIVE_VERSION,
            'customFields' => [SeedData::MARKER_FIELD => $marker, SeedData::MARKER_FIELD . '_date' => $storedDate],
        ]], SeedServices::systemContext());
        return ['id' => $quote->id, 'state' => $quote->state ?? '', 'date' => $storedDate];
    }

    /**
     * @param array{id: string, state: string, date: string} $quote
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function ready(array $quote): void
    {
        if (!in_array($quote['state'], ['open', 'replied'], true)) {
            throw new \RuntimeException(
                'Seed quote ' . $quote['id'] . ' cannot be ordered in state ' . $quote['state'] . '.',
            );
        }
        $context = SeedServices::systemContext();
        $this->services->repository('quote.repository')->update([[
            'id' => $quote['id'],
            'versionId' => Defaults::LIVE_VERSION,
            'expirationDate' => (new \DateTimeImmutable('+14 days'))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]], $context);
        if ($quote['state'] === 'open') {
            $transitioner = $this->services->transitioner();
            $transitioner->transition($quote['id'], QuoteTransition::Sent, $context);
        }
    }
}
