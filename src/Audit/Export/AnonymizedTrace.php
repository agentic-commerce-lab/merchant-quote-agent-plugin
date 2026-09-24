<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit\Export;

use MerchantQuoteAgentPlugin\Audit\TraceEvent;
use MerchantQuoteAgentPlugin\Audit\TraceKind;

/**
 * One trace row as one element of its decision line's `trace` list.
 *
 * The split was made when the event was recorded. The export checks the
 * TraceKind allowlist again so unexpected stored keys cannot leave with every
 * export. `content` leaves only with free text. No id of the event's
 * own leaves: a nested event is identified by its decision line and its place
 * in the list.
 *
 * `content` does carry raw ids -- a quote snapshot names the quote, the
 * customer and the channel, and so can a prompt -- and the export promises
 * those leave only as pseudonyms, free text or not. So the decision's own ids
 * are swapped for their pseudonyms inside the encoded content. Line-item and
 * product ids are not on that promise's list and stay as they are.
 */
final class AnonymizedTrace
{
    private function __construct() {}

    /**
     * @param array<string, string> $pseudonyms raw id => its export pseudonym
     *
     * @return array<string, mixed>
     */
    public static function of(TraceEvent $event, bool $freeText, array $pseudonyms = []): array
    {
        $kind = TraceKind::tryFrom($event->kind);
        $allowed = array_fill_keys([...($kind?->metaKeys() ?? []), 'truncated'], true);
        $line = [
            'kind' => $event->kind,
            'occurredAt' => $event->occurredAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'meta' => array_intersect_key($event->meta ?? [], $allowed),
        ];

        return $freeText ? [...$line, 'content' => self::pseudonymized($event->content, $pseudonyms)] : $line;
    }

    /**
     * On the encoded string, not by walking keys: an id can sit inside prose
     * ("quote 3f2a…") as well as in a field. strtr() replaces each id once and
     * never re-reads what it wrote.
     *
     * @param array<array-key, mixed>|null $content
     * @param array<string, string> $pseudonyms
     *
     * @return array<array-key, mixed>|null
     */
    private static function pseudonymized(?array $content, array $pseudonyms): ?array
    {
        if ($content === null || $pseudonyms === []) {
            return $content;
        }

        $encoded = json_encode(
            $content,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $decoded = \is_string($encoded) ? json_decode(strtr($encoded, $pseudonyms), true) : null;

        return \is_array($decoded) ? $decoded : null;
    }
}
