<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * #174: `ReplyTemplate::reduction()` used to clamp a negative movement to
 * `0.0`, which rendered a price INCREASE as a cheerful "0% off" -- the exact
 * shape that reached two buyers as a discount (quotes 1039, 1048).
 *
 * `OfferApplier`'s never-raise check DETECTS -- it cannot prevent -- a write
 * that lands above the quote's pre-write total (there is no rollback; see
 * that class's own docblock) and marks the pass unverified before
 * `reduction()` is ever called with the result. A negative movement reaching
 * here means that detection itself broke, not a case to render politely.
 *
 * `OfferRound` catches this at the composition site and escalates the pass
 * through the same funnel a verification failure already uses
 * (`QuoteEscalationReason::VerificationFailed`) rather than letting it
 * propagate: this type is unchecked (`RuntimeException`), so left uncaught it
 * would leave `NegotiationPipeline::run()`'s handled cases, and
 * `ServiceQuoteHandler` would rethrow it for Messenger to redeliver against a
 * quote that already carries the bad write, with no reply ever reaching the
 * buyer -- worse than the clamped `0%` this replaces. Failing loudly here and
 * catching it one frame up is what turns "the invariant broke" into a human
 * escalation instead of a retry loop against an already-wrong quote.
 */
final class NegativeReduction extends \RuntimeException {}
