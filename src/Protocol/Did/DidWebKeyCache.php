<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Protocol\Did;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * A short-lived, shared cache for the public keys behind `did:web` documents.
 *
 * DidWebResolver already memoizes within one request. That is not enough: PHP
 * builds a fresh container per request, so a counterparty's DID document was
 * being fetched again on every single call. A live interoperability session
 * of 54 seconds pulled the buyer's document fifteen times.
 *
 * Two reasons that matters, and the second is the sharper one. It puts a
 * third-party HTTPS round trip on the latency path of every act. And because
 * the transport token is verified before the request body is looked at, a
 * caller who is never going to authenticate can still make this shop issue one
 * outbound request per attempt, to any public host they name — so an
 * unauthenticated party chooses who absorbs that traffic. It is bounded
 * (DidWebDocumentFetcher validates the URL, caps the body at 256 KiB and times
 * out after 5 seconds) and it amplifies about one to one, but a cache removes
 * it almost entirely.
 *
 * Failures are cached too, but for far less time, and the asymmetry is the
 * point. A negative entry sits on the authentication path: a counterparty
 * whose host blips for one request would otherwise be answered `401` for the
 * whole window, having done nothing wrong. Fifteen seconds still collapses a
 * burst — a flood arrives far faster than that — while a legitimate retry
 * recovers almost immediately. It buys less against a determined attacker,
 * who can rotate DIDs and defeat any per-DID entry, and that is the right
 * trade: the damping is for repetition, not for adversaries.
 *
 * TTLs are deliberately short on both sides. A DID document changes when a key
 * rotates, and a rotation that takes five minutes to be seen is a rotation
 * that works; a stale key held for an hour is one that does not.
 *
 * A null pool means no cross-request caching at all, which is what the unit
 * tests want when they are counting fetches.
 */
final readonly class DidWebKeyCache
{
    private const HIT_TTL_SECONDS = 300;

    private const MISS_TTL_SECONDS = 15;

    private const PREFIX = 'mqa_a2cn_didweb_';

    public function __construct(
        private ?CacheItemPoolInterface $pool = null,
    ) {}

    /**
     * The cached key for this verification method, resolving it through
     * `$resolve` on a miss.
     *
     * Any trouble with the cache itself falls through to `$resolve`: a broken
     * cache must slow this path down, never break it.
     *
     * @param \Closure(): ?string $resolve
     */
    public function through(string $verificationMethod, \Closure $resolve): ?string
    {
        if ($this->pool === null) {
            return $resolve();
        }

        try {
            return $this->stored($verificationMethod, $resolve);
        } catch (InvalidArgumentException) {
            return $resolve();
        }
    }

    /**
     * @param \Closure(): ?string $resolve
     *
     * @throws InvalidArgumentException
     */
    private function stored(string $verificationMethod, \Closure $resolve): ?string
    {
        \assert($this->pool !== null);

        // Hashed, because a verification method is a DID URL and PSR-6 reserves
        // `:` and `/` in a key.
        $item = $this->pool->getItem(self::PREFIX . hash('sha256', $verificationMethod));

        if ($item->isHit()) {
            $hit = $item->get();

            // A cached miss is stored as null and still reports as a hit.
            return \is_string($hit) ? $hit : null;
        }

        $pem = $resolve();
        $this->pool->save($item->set($pem)->expiresAfter(
            $pem === null ? self::MISS_TTL_SECONDS : self::HIT_TTL_SECONDS,
        ));

        return $pem;
    }
}
