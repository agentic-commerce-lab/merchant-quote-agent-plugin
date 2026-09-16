<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Turns one shop-local id into a stable string that means nothing outside this
 * shop. The same customer is the same pseudonym in every export this shop
 * makes, and a different pseudonym in every other shop's — which is what lets
 * repeat-buyer effects be studied without anyone learning who the buyer is.
 *
 * The salt lives in `system_config`, deliberately OUTSIDE the
 * `MerchantQuoteAgentPlugin.config.*` prefix the admin settings form renders,
 * for the reason A2cnKeyStore gives for the signing key: material like this
 * must not appear in a config screen where it gets copied into a support
 * ticket. AGENTS.md's "never read system_config directly" rule is about
 * MERCHANT configuration, which goes through QuoteAgentSettingsReader; this is
 * plugin-owned key material the merchant never sets, so it follows
 * A2cnKeyStore instead.
 *
 * Unlike A2cnKeyStore, an absent salt is generated rather than refused. A
 * signing key generated twice by two racing requests silently invalidates
 * every act signed with the loser, which is why that class throws. A salt
 * generated twice costs at most the linkability between two exports, it cannot
 * race in practice (one console process, run by hand), and refusing would lock
 * every install made before this version out of exporting at all until it was
 * reinstalled.
 *
 * Deleting the config row therefore breaks the link between past and future
 * exports — a valid way to sever it on purpose, and documented as such in
 * docs/for-merchants.md.
 */
final readonly class ExportPseudonym
{
    public const CONFIG_KEY = 'MerchantQuoteAgentPlugin.export.pseudonymSalt';

    /** Half a sha256, which is plenty against collision at any volume this table reaches, and keeps a JSONL line readable. */
    private const WIDTH = 32;

    public function __construct(
        private string $salt,
    ) {}

    /** @throws \Random\RandomException */
    public static function forShop(SystemConfigService $systemConfig): self
    {
        $stored = $systemConfig->get(self::CONFIG_KEY);

        if (\is_string($stored) && $stored !== '') {
            return new self($stored);
        }

        $salt = bin2hex(random_bytes(32));
        $systemConfig->set(self::CONFIG_KEY, $salt);

        return new self($salt);
    }

    public function of(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $id, $this->salt), 0, self::WIDTH);
    }
}
