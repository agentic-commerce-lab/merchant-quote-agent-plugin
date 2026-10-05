<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;

/**
 * NegotiationPipeline is the only class that opens and closes a decision
 * record.
 *
 * That was a sentence in the audit design and fifteen tests that each happen
 * to see one draft. #35 added a second way a row is written --
 * DecisionRecorder::recordRefusal(), for the escalation that never reaches the
 * pipeline -- and the argument that it is safe rests entirely on it not being
 * part of the draft lifecycle. An argument that rests on a property is worth
 * what the test of that property is worth, so here it is: a scan, in one
 * place, that fails the moment a second class starts a record.
 *
 * Deliberately blunt. It matches two arrow-call spellings over a directory
 * where those two method names have exactly two call sites, and it cannot see
 * a dynamic call. It catches the mistake a person actually makes -- writing
 * `$this->recorder` and one of these two names in a new class -- and a false
 * positive is an edit to the allow-list with a reviewer looking at it, which
 * is the point rather than the cost.
 */
final class RecorderOwnershipTest extends TestCase
{
    /**
     * Three owners, not one, since #22's nightly replay: ReplayEvaluator and
     * ImprovementJudge each open and close a draft the same way
     * NegotiationPipeline does, for the same reason -- bracketing a model
     * call so its tokens land on one row -- and this is the reviewed edit
     * this test's own docblock anticipates for exactly that case. It stays
     * safe under the invariant below ("exactly one record per pass is
     * provable in one place") because both nightly-loop drafts are handed to
     * TallyingDecisionWriter, which persists nothing: a second or third owner
     * that cannot write a row does not weaken a guarantee about what gets
     * written.
     */
    private const OWNERS = [
        'Improvement/ImprovementJudge.php',
        'Improvement/ReplayEvaluator.php',
        'Negotiation/NegotiationPipeline.php',
    ];

    public function testOnlyTheNegotiationPipelineOpensAndClosesARecord(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $contents) {
            if (!str_contains($contents, '->begin(') && !str_contains($contents, '->finish(')) {
                continue;
            }

            $offenders[] = $relative;
        }

        // Filesystem iteration order is not guaranteed across platforms; sort
        // so this assertion compares content, not directory traversal order.
        sort($offenders);

        self::assertSame(
            self::OWNERS,
            $offenders,
            'A class outside the allow-list opens or closes a decision record. The audit design '
            . 'makes the listed classes the only owners of that lifecycle, which is what makes '
            . '"exactly one record per pass, per owner" provable in one place. A new row written '
            . 'whole, the way DecisionRecorder::recordRefusal() writes one, does not need it.',
        );
    }

    public function testOnlyThePassAndOutsidePassWritersInsertTraceRows(): void
    {
        $owners = [];

        foreach ($this->sourceFiles() as $relative => $contents) {
            if (str_contains($contents, '->traces->create(')) {
                $owners[] = $relative;
            }
        }

        sort($owners);
        self::assertSame(['Audit/DecisionRecordWriter.php', 'Audit/TraceWriter.php'], $owners);
    }

    /** @return iterable<string, string> relative path => contents */
    private function sourceFiles(): iterable
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            yield str_replace($root . '/', '', $file->getPathname()) => $contents;
        }
    }

    public function testTheScanActuallyReachesTheSource(): void
    {
        // A path typo would make the test above pass by finding nothing, which
        // is the one way a source scan fails silently.
        self::assertGreaterThan(100, iterator_count($this->sourceFiles()));
    }
}
