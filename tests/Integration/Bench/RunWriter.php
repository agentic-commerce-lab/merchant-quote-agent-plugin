<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

/**
 * Appends one JSON object per line to a run's own file under `var/bench/`
 * (git-ignored -- run output is never committed), field names matching the
 * decision record's own so Task 8's scorer can feed a row straight into the
 * admin's `measures.ts` with no translation layer to drift.
 *
 * Opens the handle once and keeps it for the whole run rather than reopening
 * per row: `BenchRunTest` calls `writeRow()` from inside three nested loops.
 */
final class RunWriter
{
    /** @var resource */
    private $handle;

    public function __construct(string $path)
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, permissions: 0o775, recursive: true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Could not create bench output directory "%s".', $directory));
        }

        $handle = fopen($path, mode: 'a');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Could not open bench output file "%s" for writing.', $path));
        }

        $this->handle = $handle;
    }

    /** @param array<string, mixed> $row */
    public function writeRow(array $row): void
    {
        fwrite($this->handle, json_encode($row, \JSON_THROW_ON_ERROR) . "\n");
    }

    public function close(): void
    {
        fclose($this->handle);
    }
}
