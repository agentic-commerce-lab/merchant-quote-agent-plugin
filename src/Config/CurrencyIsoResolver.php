<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Config;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Re-keys the admin's price field from currency ids to ISO codes.
 *
 * Shopware stores a `type="price"` config field as
 * `[{currencyId, net, gross, linked}, ...]` — one row per currency the
 * merchant filled in, keyed by uuid. Only the NET value is carried over: a
 * system config field has no tax rate, so sw-price-field's gross box just
 * mirrors net, and the whole policy is stated net anyway.
 *
 * Types are deliberately NOT checked here. A null net is dropped, because
 * Shopware seeds a row for the system currency whether or not the merchant
 * typed anything and a blank field means "no ceiling for this currency". Every
 * other value is passed through for QuoteLimits to refuse, so a string "50000"
 * from `system:config:set` without `--json` becomes a named configuration error
 * rather than a ceiling that quietly vanished.
 *
 * @mago-expect lint:cyclomatic-complexity
 * The rule aggregates per class (threshold 10) and every branch is one shape
 * `system_config` can actually hold: not a list at all, a row that is not an
 * array, a row with no currency id, a blank net, and a currency that no longer
 * exists. `array_column()` would collapse the first three into no branches at
 * all and was tried — it raises TypeError on a non-scalar id, and this runs in
 * the reader, OUTSIDE the factory's `catch (\TypeError)`, so a hand-edited row
 * would 500 the mandate route instead of 404ing it.
 */
final readonly class CurrencyIsoResolver
{
    /** @param EntityRepository<\Shopware\Core\System\Currency\CurrencyCollection> $currencyRepository */
    public function __construct(
        private EntityRepository $currencyRepository,
    ) {}

    /**
     * @return array<string, mixed>|null null when the value is not a price
     *                                   list at all, so the caller leaves it exactly as it found it — a
     *                                   wrong-shaped value is the factory's to refuse, not this class's to
     *                                   guess at
     */
    public function netByIso(mixed $prices): ?array
    {
        if (!\is_array($prices)) {
            return null;
        }

        $netById = [];

        foreach ($prices as $price) {
            $id = \is_array($price) ? $price['currencyId'] ?? null : null;

            if (\is_string($id) && ($price['net'] ?? null) !== null) {
                $netById[$id] = $price['net'];
            }
        }

        return $netById === [] ? [] : $this->keyByIso($netById);
    }

    /**
     * @param array<string, mixed> $netById
     *
     * @return array<string, mixed>
     */
    private function keyByIso(array $netById): array
    {
        $criteria = new Criteria(array_keys($netById));
        $byIso = [];

        // The collection is keyed by id, looked up rather than trusted: a
        // repository that returned a row we did not ask for would otherwise
        // read an undefined key and put a null ceiling in the map, and a null
        // ceiling is a currency taken out of service. A currency deleted since
        // the ceiling was set simply does not come back, which leaves it out of
        // the map rather than keying it by a uuid no quote could ever match.
        foreach ($this->currencyRepository->search($criteria, Context::createDefaultContext()) as $id => $currency) {
            $iso = $currency->get('isoCode');

            $net = $netById[$id] ?? null;

            if (\is_string($iso) && $net !== null) {
                $byIso[$iso] = $net;
            }
        }

        return $byIso;
    }
}
