<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

/** A throwable and its getPrevious() walk, flattened for the JSON column. */
final class ErrorChain
{
    private const MAX_DEPTH = 10;

    private function __construct() {}

    /** Sets the draft's error columns from $error, or does nothing when it is null. */
    public static function applyTo(DecisionDraft $draft, ?\Throwable $error): void
    {
        if ($error === null) {
            return;
        }

        $draft->errorClass = $error::class;
        $draft->errorChain = self::of($error);
    }

    /** @return list<array<string, string>> */
    public static function of(\Throwable $error): array
    {
        $chain = [];
        $current = $error;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            $chain[] = [
                'class' => $current::class,
                'message' => $current->getMessage(),
                'at' => $current->getFile() . ':' . $current->getLine(),
            ];
            $current = $current->getPrevious();
            ++$depth;
        }

        return $chain;
    }
}
