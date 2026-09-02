<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Identity\Authorization;

use Ucp\Sdk\Exception\ValidationException;

/**
 * What may be stored as a `redirect_uri`, mirroring Agentic Commerce's
 * `OAuthClientBindingValidator::assertRedirectUri()`.
 *
 * This is a security check, not a convenience one. The GRANT path is covered
 * by AC re-running its own rules inside `authorize()`, but DENIAL never
 * reaches AC — `PendingAuthorizationPresenter::denialUrl()` sends the browser
 * to the stored URI raw — so whatever is accepted here is somewhere the shop
 * will 302 a signed-in customer's browser, chosen by a verified agent.
 * Registration is the only gate on that path.
 *
 * AC's rules, both mirrored:
 *
 * - https, except `http://localhost` during local development;
 * - the same origin — scheme, host AND port — as `client_id`, with the same
 *   localhost exemption AC grants when both sides are on localhost.
 *
 * Plus one of our own, because ours is the redirect AC never sees: no
 * fragment. A fragment is never sent to a server, so `denialUrl()`'s appended
 * query string would land inside it, and the shop would report success against
 * a URL nobody receives. Userinfo is refused as AC's `urlParts()` does.
 *
 * Its own class rather than another method on {@see PayloadFields} because the
 * two together exceed the class-level cyclomatic-complexity gate, and this is
 * the half with a reason to be read on its own.
 */
final readonly class RedirectUriRule
{
    /**
     * The whole acceptable URL, anchored at both ends, capturing scheme, host
     * and port. Every rule that is not about the origin comparison is
     * expressed here rather than as another branch below, which is what keeps
     * this class inside the complexity gate:
     *
     * - only `http`/`https` — `javascript:` and friends cannot match;
     * - the host class excludes `@`, so userinfo is refused, as AC's
     *   `urlParts()` refuses it via `isset($parts['user'])`;
     * - `[^#\s]*` with a closing `$` means no fragment anywhere, and no
     *   whitespace or control byte either. `\s` covers space, tab, CR, LF,
     *   form feed and vertical tab, none of which belongs unencoded in a URI —
     *   a legitimate one percent-encodes them. CR and LF are the ones that
     *   matter: `requiredString()`'s `trim()` strips edges only, the stored
     *   string reaches `PendingAuthorizationPresenter::denialUrl()` by raw
     *   concatenation, and `RedirectResponse` puts it into the `Location`
     *   header unvalidated. PHP's `header()` has refused `\r`/`\n` since
     *   5.1.2, so the realistic outcome was a broken denial rather than a
     *   split response — but denial is the one path AC never re-validates, so
     *   this rule should not lean on a guard in the SAPI beneath it;
     * - the host class also excludes `[`, so an IPv6 literal is refused where
     *   AC would parse it. No agent callback is an IPv6 literal, and refusing
     *   one fails fast with a readable message rather than widening what the
     *   shop will redirect to.
     */
    private const ORIGIN = '~^(https?)://([A-Za-z0-9.\-]+)(?::(\d+))?(?:[/?][^#\s]*)?$~';

    public function __construct(
        private PayloadFields $fields,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    public function required(array $payload, string $key, string $clientId): string
    {
        $value = $this->fields->requiredString($payload, $key);
        $origin = self::origin($value);

        if ($origin === '') {
            throw self::refuse($key, 'must be an absolute http(s) URL with no userinfo and no fragment');
        }

        $isLocalhost = str_starts_with($origin, 'http://localhost:');

        if (!str_starts_with($origin, 'https://') && !$isLocalhost) {
            throw self::refuse($key, 'must use https, except http://localhost during local development');
        }

        $clientOrigin = self::origin($clientId);

        if ($origin !== $clientOrigin && !($isLocalhost && str_contains($clientOrigin, '://localhost:'))) {
            throw self::refuse($key, 'must use the same origin as "client_id"');
        }

        return $value;
    }

    /**
     * The origin AC compares, as one normalised `scheme://host:port` string —
     * lowercased, and with the scheme's default port filled in so
     * `https://agent.example` and `https://agent.example:443` are one origin,
     * exactly as AC's `urlParts()` treats them.
     *
     * An empty string means "not a URL this rule can accept" — see the pattern
     * for every reason that can be. Returning a value the caller refuses keeps
     * the branching in one place: an unacceptable `client_id` yields '' too,
     * which can never equal a redirect URI's non-empty origin, and an
     * unacceptable redirect URI is refused outright.
     */
    private static function origin(string $uri): string
    {
        if (preg_match(self::ORIGIN, $uri, $matches) !== 1) {
            return '';
        }

        // The annotation, not a runtime check: groups 1 and 2 are not optional
        // in the pattern, so a match always sets them — the analyzer just
        // cannot see that, and a `?? ''` for its benefit alone would cost two
        // decision points against this class's complexity budget.
        /** @var array{0: string, 1: string, 2: string, 3?: string} $matches */
        $scheme = strtolower($matches[1]);
        $port = (int) ($matches[3] ?? 0);

        if ($port === 0) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        return $scheme . '://' . strtolower($matches[2]) . ':' . $port;
    }

    private static function refuse(string $key, string $requirement): ValidationException
    {
        return new ValidationException(\sprintf('"%s" %s.', $key, $requirement), [\sprintf(
            '$.%s %s',
            $key,
            $requirement,
        )]);
    }
}
