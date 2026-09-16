<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use PHPUnit\Framework\TestCase;

/**
 * Every `QuoteEscalationReason` case must have an admin sentence, in both
 * languages, or the merchant reads a raw enum value.
 *
 * `escalationExplanation()` in decision.ts falls back to the short label when
 * a reason has no `escalationWhy` snippet, and the short label itself falls
 * back to the raw enum value when it has no `escalation` snippet either — so
 * a reason that ships with no snippet degrades silently into the merchant
 * seeing something like `round_limit_exceeded` instead of a sentence. The
 * failure mode is silent by construction, which is why it needs a test
 * rather than a convention.
 *
 * The admin's own `decision.check.mjs` self-checks cannot catch this: their
 * `vm` mock's `$t`/`$tc` never read the snippet files, they only echo the
 * translation key's last segment, so a missing snippet and a present one are
 * indistinguishable to that mock. This test reads the real JSON.
 */
final class EscalationReasonSnippetsTest extends TestCase
{
    public function testEveryEscalationReasonHasAnAdminSentenceInBothLanguages(): void
    {
        $paths = [
            'en' =>
                __DIR__ . '/../../../src/Resources/app/administration/src/module/merchant-quote-agent/snippet/en.json',
            'de' =>
                __DIR__ . '/../../../src/Resources/app/administration/src/module/merchant-quote-agent/snippet/de.json',
        ];

        foreach ($paths as $locale => $path) {
            $snippets = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $root = $snippets['merchant-quote-agent'];

            foreach (QuoteEscalationReason::cases() as $reason) {
                $value = $reason->value;

                self::assertArrayHasKey(
                    $value,
                    $root['escalation'],
                    "Missing 'escalation.{$value}' in {$path} ({$locale}): every QuoteEscalationReason case "
                    . 'needs a short label, or the merchant is shown the raw enum value.',
                );

                self::assertArrayHasKey(
                    $value,
                    $root['escalationWhy'],
                    "Missing 'escalationWhy.{$value}' in {$path} ({$locale}): every QuoteEscalationReason case "
                    . 'needs its own sentence, or escalationExplanation() falls back to the short label.',
                );
            }
        }
    }
}
