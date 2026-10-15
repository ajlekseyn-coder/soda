<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferContinue;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreferContinueTest extends TestCase
{
    #[DataProvider('loops')]
    public function testTrailingWrappedRemainders(string $source, int $count): void
    {
        self::assertCount($count, $this->findings($source));
    }

    public static function loops(): iterable
    {
        yield 'foreach wrapping the remainder' => ['function save(array $items): void { foreach ($items as $item) { mark($item); if ($item->active) { store($item); index($item); } } }', 0];
        yield 'for wrapping the remainder' => ['function save(array $items): void { for ($i = 0; $i < count($items); $i++) { mark($items[$i]); if ($items[$i]->active) { store($items[$i]); } } }', 0];
        yield 'while wrapping the remainder' => ['function drain(): void { while ($item = nextItem()) { mark($item); if ($item->active) { store($item); } } }', 0];
        yield 'do-while wrapping the remainder' => ['function drain(): void { do { mark($item); if ($item->active) { store($item); } } while ($item = nextItem()); }', 0];
        yield 'nested loop reported independently' => ['function save(array $groups): void { foreach ($groups as $group) { foreach ($group as $item) { mark($item); if ($item->active) { store($item); } } } }', 0];
        yield 'guarded branch already exits the iteration' => ['function save(array $items): void { foreach ($items as $item) { if ($item->active) { store($item); continue; } mark($item); } }', 0];
        yield 'guarded branch returns' => ['function save(array $items): bool { foreach ($items as $item) { if ($item->broken) { return false; } mark($item); } return true; }', 0];
        yield 'guarded branch breaks' => ['function save(array $items): void { foreach ($items as $item) { if ($item->last) { break; } mark($item); } }', 0];
        yield 'if with else keeps both paths' => ['function save(array $items): void { foreach ($items as $item) { if ($item->active) { store($item); } else { mark($item); } } }', 0];
        yield 'if is not the last statement' => ['function save(array $items): void { foreach ($items as $item) { if ($item->active) { store($item); } mark($item); } }', 0];
        yield 'nested if inside the guarded branch' => ['function save(array $items): void { foreach ($items as $item) { mark($item); if ($item->active) { if ($item->fresh) { store($item); } } } }', 1];
    }

    public function testReportsTheTrailingConditionWithAdvice(): void
    {
        $findings = $this->findings(<<<'PHP'
<?php

function save(array $items): void
{
    foreach ($items as $item) {
        mark($item);
        if ($item->active) {
            if ($item->fresh) {
                store($item);
            }
        }
    }
}
PHP);
        self::assertCount(1, $findings);
        self::assertSame('prefer_continue', $findings[0]->rule);
        self::assertSame(7, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('line 7', $findings[0]->message);
        self::assertStringContainsString('continue', $findings[0]->message);
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-continue-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function save(array $items): void { foreach ($items as $item) { mark($item); if ($item->active) { if ($item->fresh) { store($item); } } } }');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'prefer_continue')->values()->all();
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

        return iterator_to_array((new PreferContinue)->checkFile(new FileFacts('/continue.php', $source, $nodes, [])));
    }
}
