<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferNullCoalesceAssignment;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreferNullCoalesceAssignmentTest extends TestCase
{
    #[DataProvider('assignments')]
    public function testRepeatedFallbackTargets(string $statement, int $count): void
    {
        self::assertCount($count, $this->findings('<?php '.$statement));
    }

    public static function assignments(): iterable
    {
        yield 'plain variable' => ['$value = $value ?? "draft";', 1];
        yield 'fallback to null literal' => ['$value = $value ?? null;', 1];
        yield 'array dimension' => ['$cache["key"] = $cache["key"] ?? [];', 1];
        yield 'property fetch' => ['$this->scope = $this->scope ?? "web";', 1];
        yield 'chained fallback' => ['$value = $value ?? $fallback ?? "draft";', 1];
        yield 'different target' => ['$value = $other ?? "draft";', 0];
        yield 'different key' => ['$cache["key"] = $cache["other"] ?? [];', 0];
        yield 'shorthand ternary stays outside' => ['$value = $value ?: "draft";', 0];
        yield 'plain assignment without fallback' => ['$value = $other ?? "draft";', 0];
        yield 'conditional on the right side' => ['$value = $value ?? compute();', 1];
        yield 'call on the left side stays outside' => ['$value = read($value) ?? "draft";', 0];
        yield 'target assigned through a call stays outside' => ['$cache[resolve()] = $cache[resolve()] ?? [];', 0];
        yield 'assignment inside a loop' => ['foreach ($rows as $row) { $total = $total ?? 0; }', 1];
    }

    public function testReportsTheTargetWithAdvice(): void
    {
        $findings = $this->findings('<?php $cache["key"] = $cache["key"] ?? [];');
        self::assertCount(1, $findings);
        self::assertSame('prefer_null_coalesce_assignment', $findings[0]->rule);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('$cache["key"]', $findings[0]->message);
        self::assertStringContainsString('??=', $findings[0]->message);
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-coalesce-');
        self::assertIsString($path);
        file_put_contents($path, '<?php $value = $value ?? "draft";');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'prefer_null_coalesce_assignment')->values()->all();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    private function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        return iterator_to_array((new PreferNullCoalesceAssignment)->checkFile(new FileFacts('/coalesce.php', $source, $nodes, [])));
    }
}
