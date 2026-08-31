<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * One lock per quote id, replacing the in-process `inFlight` map of the
 * TypeScript path that was only correct while exactly one instance ran.
 *
 * The TTL matters as much as the lock. `addProduct` on a variant product
 * segfaults the PHP process (exit 139, no exception — #3), so a failure can be
 * a dropped worker rather than a thrown error, and a lock with no TTL would
 * wedge that quote permanently. Not auto-refreshed: a pass that outlives the
 * TTL is a problem to see in the logs, not to paper over.
 *
 * The store is the merchant's, taken from the shop's LOCK_DSN — the plugin does
 * not substitute its own, because a lock store that disagrees with everything
 * else in the shop is worse than a documented ceiling. `flock` (Shopware's
 * default, set in core's own framework.yaml) and `semaphore` are host-local, so
 * they give no cross-node exclusion; that gets one warning rather than silence.
 *
 * "Host-local" overstates what `flock` actually delivers, which is why the
 * warning says less than that. Symfony's FlockStore keys its lock file on
 * `sys_get_temp_dir()`, so two processes exclude each other only while they
 * share a `/tmp` — and a web server unit under systemd `PrivateTmp=yes` does
 * not share one with a CLI process on the same machine. Shopware's admin
 * worker runs inside that web request; a `messenger:consume` worker does not.
 * Measured on a shop running both: two passes claimed one quote a second
 * apart and the buyer got two replies to one ask.
 */
final class QuoteServicingLock
{
    public const TTL_SECONDS = 300.0;

    private const KEY_PREFIX = 'merchant-quote-agent.quote.';

    /** Symfony store DSNs that only coordinate processes on one host. */
    private const HOST_LOCAL_DSN_PREFIXES = ['flock', 'semaphore'];

    private bool $warned = false;

    public function __construct(
        private readonly LockFactory $factory,
        private readonly string $lockDsn,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function for(string $quoteId): LockInterface
    {
        $this->warnOnceIfHostLocal();

        return $this->factory->createLock(self::KEY_PREFIX . $quoteId, self::TTL_SECONDS);
    }

    private function warnOnceIfHostLocal(): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        foreach (self::HOST_LOCAL_DSN_PREFIXES as $prefix) {
            if (!str_starts_with($this->lockDsn, $prefix)) {
                continue;
            }

            $this->logger?->warning('Quote servicing locks are host-local at best: this store excludes two '
            . 'processes only while they share a /tmp, which a web server under systemd '
            . 'PrivateTmp does not do with a CLI worker — so Shopware\'s admin worker and '
            . 'messenger:consume can service one quote at the same time and reply twice. Switch '
            . 'the admin worker off once a real worker runs, and point LOCK_DSN at a shared '
            . 'store before running workers on more than one node.', ['lockDsn' => $this->lockDsn]);

            return;
        }
    }
}
