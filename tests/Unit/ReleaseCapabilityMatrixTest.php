<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * What each supported SwagCommercial release actually declares, read from the
 * clone rather than remembered.
 *
 * The plugin probes DAL fields at runtime precisely because releases differ in
 * ways a version number does not predict — `quote.cart_payload` landed in 6.7.9,
 * mid-line. That design is only as good as our belief about which release has
 * which field, and this is the only thing that checks that belief. When a
 * backport lands, this test fails and the fix is to update the matrix, not the
 * probe.
 *
 * Skipped without the clone: it is a developer-machine convenience, and CI has
 * no access to a licensed private repository.
 */
final class ReleaseCapabilityMatrixTest extends TestCase
{
    private const CLONE_PATH = '/Users/sebastian/projects/swagcommercial';

    private const LINE_ITEM_DEFINITION = 'src/B2B/QuoteManagement/Entity/QuoteLineItem/QuoteLineItemDefinition.php';

    private const COMMENT_DEFINITION = 'src/B2B/QuoteManagement/Entity/QuoteComment/QuoteCommentDefinition.php';

    /**
     * @return iterable<string, array{string, bool, bool, bool}>
     *     tag => [tag, lineItemAsks, softDeleteLines, lineScopedComments]
     */
    public static function releases(): iterable
    {
        yield 'floor 6.7.1.2' => ['v6.7.1.2', false, false, false];
        yield '6.7.5.0' => ['v6.7.5.0', false, false, false];
        yield '6.7.9.1' => ['v6.7.9.1', false, false, false];
        yield 'newest release 6.7.12.0' => ['v6.7.12.0', false, false, false];
        yield 'unreleased trunk' => ['trunk', true, true, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('releases')]
    public function testTheDeclaredFieldsMatchOurCapabilityMatrix(
        string $tag,
        bool $lineItemAsks,
        bool $softDeleteLines,
        bool $lineScopedComments,
    ): void {
        $lineItem = self::show($tag, self::LINE_ITEM_DEFINITION);
        $comment = self::show($tag, self::COMMENT_DEFINITION);

        self::assertSame(
            $lineItemAsks,
            str_contains($lineItem, "'requested_price'"),
            $tag . ' disagrees with the matrix about quote_line_item.requested_price',
        );
        self::assertSame(
            $softDeleteLines,
            str_contains($lineItem, "'deleted_at'"),
            $tag . ' disagrees with the matrix about quote_line_item.deleted_at',
        );
        self::assertSame(
            $lineScopedComments,
            str_contains($comment, "'quote_line_item_id'"),
            $tag . ' disagrees with the matrix about quote_comment.quote_line_item_id',
        );
    }

    public function testTheSupportFloorIsWhereCommentEmployeeIdAppears(): void
    {
        self::assertStringNotContainsString(
            "'employee_id'",
            self::show('v6.7.0.1', self::COMMENT_DEFINITION),
            '6.7.0.x should lack employee_id — it is why the floor is 6.7.1.2',
        );
        self::assertStringContainsString(
            "'employee_id'",
            self::show('v6.7.1.2', self::COMMENT_DEFINITION),
            'the support floor must have employee_id; QuoteCommentMapper reads it unguarded',
        );
    }

    private static function show(string $tag, string $path): string
    {
        if (!is_dir(self::CLONE_PATH . '/.git')) {
            self::markTestSkipped('No SwagCommercial clone at ' . self::CLONE_PATH);
        }

        $command = sprintf(
            'git -C %s show %s 2>/dev/null',
            escapeshellarg(self::CLONE_PATH),
            escapeshellarg($tag . ':' . $path),
        );

        $output = shell_exec($command);

        self::assertIsString($output, 'git show failed for ' . $tag . ':' . $path);
        self::assertNotSame('', trim($output), 'empty file for ' . $tag . ':' . $path);

        return $output;
    }
}
