<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoNestedTernary;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoNestedTernaryTest extends TestCase
{
    #[DataProvider('expressions')]
    public function testNestingBoundaries(string $expression, int $count): void
    {
        self::assertCount($count, $this->findings('<?php $result = '.$expression.';'));
    }

    public static function expressions(): array
    {
        return [
            'true branch'                    => ['$premium ? ($annual ? 100 : 10) : 20', 1],
            'false branch'                   => ['$premium ? 100 : ($annual ? 10 : 20)', 1],
            'both branches reported once'    => ['$premium ? ($annual ? 100 : 10) : ($trial ? 0 : 20)', 1],
            'three decisions'                => ['$premium ? ($annual ? ($discount ? 90 : 100) : 10) : 20', 2],
            'single decision'                => ['$premium ? 100 : 20', 0],
            'shorthand chain'                => ['$first ?: $second ?: $third', 0],
            'null coalescing chain'          => ['$first ?? $second ?? $third', 0],
            'inner shorthand'                => ['$premium ? ($value ?: 10) : 20', 0],
            'outer shorthand'                => ['$value ?: ($premium ? 100 : 20)', 0],
            'condition is separate policy'   => ['($premium ? $annual : $trial) ? 100 : 20', 0],
            'call argument is separate'      => ['$premium ? price($annual ? 100 : 10) : 20', 0],
            'closure is separate'            => ['$premium ? fn () => $annual ? 100 : 10 : fn () => 20', 0],
            'nested decision inside closure' => ['$premium ? fn () => $annual ? ($discount ? 90 : 100) : 10 : fn () => 20', 1],
            'match result'                   => ['match ($value) { 1 => $premium ? 100 : 20, default => 0 }', 0],
        ];
    }

    public function testDiagnosticPointsToOuterDecision(): void
    {
        $findings = $this->findings("<?php\n\$result = \$premium\n    ? (\$annual ? 100 : 10)\n    : 20;");
        self::assertCount(1, $findings);
        self::assertSame('no_nested_ternary', $findings[0]->rule);
        self::assertSame('/ternary.php', $findings[0]->file);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('preserving evaluation order', $findings[0]->message);
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-ternary-');
        self::assertIsString($path);
        file_put_contents($path, '<?php $result = $premium ? ($annual ? 100 : 10) : 20;');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard()));
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'no_nested_ternary')->values()->all();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    private function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        return iterator_to_array((new NoNestedTernary)->checkFile(new FileFacts('/ternary.php', $source, $nodes, [])));
    }
}
