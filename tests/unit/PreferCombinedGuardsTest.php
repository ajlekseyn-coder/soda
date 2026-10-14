<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferCombinedGuards;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreferCombinedGuardsTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testAdjacentIdenticalGuards(string $source, int $count): void
    {
        self::assertCount($count, $this->findings($source));
    }

    public static function bodies(): iterable
    {
        yield 'plain returns' => ['function check(bool $finished, bool $cancelled): void { if ($finished) { return; } if ($cancelled) { return; } work(); }', 1];
        yield 'same thrown expression' => ['function check(int $value): void { if ($value < 0) { throw new \InvalidArgumentException("bad"); } if ($value > 100) { throw new \InvalidArgumentException("bad"); } work(); }', 1];
        yield 'same break in a loop' => ['function check(array $items): void { foreach ($items as $item) { if ($item->hidden) { break; } if ($item->gone) { break; } work($item); } }', 1];
        yield 'same continue in a loop' => ['function check(array $items): void { foreach ($items as $item) { if ($item->hidden) { continue; } if ($item->gone) { continue; } work($item); } }', 1];
        yield 'same returned value' => ['function check(bool $first, bool $second): int { if ($first) { return 0; } if ($second) { return 0; } return 1; }', 1];
        yield 'different exit kinds' => ['function check(bool $first, bool $second): void { if ($first) { return; } if ($second) { throw new \RuntimeException("no"); } work(); }', 0];
        yield 'different returned values' => ['function check(bool $first, bool $second): int { if ($first) { return 0; } if ($second) { return 2; } return 1; }', 0];
        yield 'different break levels' => ['function check(array $grid): void { foreach ($grid as $row) { foreach ($row as $item) { if ($item->gone) { break; } if ($item->hidden) { break 2; } work($item); } } }', 0];
        yield 'multiline exits still match' => ["function check(bool \$first, bool \$second): void { if (\$first) {\n        return;\n    }\n\n    if (\$second) {\n        return;\n    }\n}", 1];
        yield 'else keeps the branch open' => ['function check(bool $first, bool $second): void { if ($first) { return; } else { work(); } if ($second) { return; } work(); }', 0];
        yield 'bodies with more than one statement' => ['function check(bool $first, bool $second): void { if ($first) { work(); return; } if ($second) { work(); return; } finish(); }', 0];
        yield 'non-adjacent guards' => ['function check(bool $first, bool $second): void { if ($first) { return; } work(); if ($second) { return; } finish(); }', 0];
        yield 'exits assigning values stay outside' => ['function check(bool $first, bool $second): int { if ($first) { return $count = next(); } if ($second) { return $count = next(); } return 1; }', 0];
    }

    public function testReportsTheSecondGuardWithEvidence(): void
    {
        $findings = $this->findings(<<<'PHP'
<?php

function check(bool $finished, bool $cancelled, bool $stalled): void
{
    if ($finished) {
        return;
    }

    if ($cancelled) {
        return;
    }

    if ($stalled) {
        return;
    }

    work();
}
PHP);
        self::assertCount(2, $findings);
        self::assertSame('prefer_combined_guards', $findings[0]->rule);
        self::assertSame([9, 13], array_column($findings, 'line'));
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('lines 5 and 9', $findings[0]->message);
        self::assertStringContainsString('lines 9 and 13', $findings[1]->message);
        self::assertStringContainsString('evaluation order is preserved', $findings[0]->message);
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-guards-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function check(bool $a, bool $b): void { if ($a) { return; } if ($b) { return; } work(); }');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'prefer_combined_guards')->values()->all();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    private function findings(string $source): array
    {
        $source = str_starts_with($source, '<?php') ? $source : '<?php '.$source;
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        return iterator_to_array((new PreferCombinedGuards)->checkFile(new FileFacts('/guards.php', $source, $nodes, [])));
    }
}
