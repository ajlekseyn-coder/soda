<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoDeclarationsBeforeEarlyExit;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoDeclarationsBeforeEarlyExitTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testDeclarationsBehindGuards(string $source, int $count): void
    {
        self::assertCount($count, $this->findings($source));
    }

    public static function bodies(): iterable
    {
        yield 'snapshot before removing an array key' => ['function run(array $options, $ready) { $trial = $options["trial"] ?? null; unset($options["trial"]); if (!$ready) { return; } save($trial); }', 0];
        yield 'snapshot before overwriting an input' => ['function run($input, $ready) { $original = $input; $input = normalize($input); if (!$ready) { return; } save($original); }', 0];
        yield 'snapshot before a guard assignment' => ['function run($input) { $original = $input; if (($input = nextInput()) === null) { return; } save($original); }', 0];
        yield 'snapshot before incrementing an input' => ['function run($input, $ready) { $original = $input; ++$input; if (!$ready) { return; } save($original); }', 0];
        yield 'snapshot before destructuring overwrites an input' => ['function run($input, $ready) { $original = $input; [$input] = nextInputs(); if (!$ready) { return; } save($original); }', 0];
        yield 'snapshot before a loop binding overwrites an input' => ['function run($input, $ready, $items) { $original = $input; foreach ($items as $input) {} if (!$ready) { return; } save($original); }', 0];
        yield 'unrelated mutation permits moving the declaration' => ['function run($input, $ready, $other) { $original = $input; unset($other["trial"]); if (!$ready) { return; } save($original); }', 1];
        yield 'input used as an index is not overwritten' => ['function run($input, $ready, $other) { $original = $input; $other[$input] = true; if (!$ready) { return; } save($original); }', 1];
        yield 'write confined to the exiting path does not change the surviving input' => ['function run($input, $ready) { $original = $input; if (!$ready) { $input = null; return; } save($original); }', 1];
        yield 'switch cases have separate statement lists' => ['function run($status, $ready) { switch ($status) { case 1: $result = compute(); if (!$ready) { return; } useIt($result); break; case 2: useOther(); break; } }', 1];
        yield 'switch does not merge declarations across cases' => ['function run($status, $ready) { switch ($status) { case 1: $result = compute(); break; case 2: if (!$ready) { return; } useIt($result); break; } }', 0];
        yield 'JSON error guard observes the decoding operation' => ['function decode($json) { $data = json_decode($json, true); if (json_last_error() !== JSON_ERROR_NONE) { return null; } return $data; }', 0];
        yield 'unrelated guard can precede JSON decoding' => ['function decode($json, $ready) { $data = json_decode($json, true); if (!$ready) { return null; } return $data; }', 1];
        yield 'JSON error message observes the encoding operation' => ['function encode($data) { $json = json_encode($data); if (json_last_error_msg() !== "No error") { throw new RuntimeException(); } return $json; }', 0];
        yield 'retry jump must not restart the clock' => ['function retry($callback) { $start = time(); beginning: if ($output = $callback()) { return $output; } if (time() - $start < 10) { goto beginning; } return null; }', 0];
        yield 'reference assignment establishes an observable binding' => ['function run(&$target, &$source, $ready) { $target = &$source; if (!$ready) { return; } consume($target); }', 0];
        yield 'nested closure checked once' => ['function outer() { register(function ($items) { foreach ($items as $item) { $prepared = prepare($item); if ($item->skip) { continue; } save($prepared); } }); }', 1];
        yield 'nested named function checked once' => ['function outer() { function inner($items) { foreach ($items as $item) { $prepared = prepare($item); if ($item->skip) { continue; } save($prepared); } } }', 1];
        yield 'anonymous class method checked once' => ['function outer() { return new class { function run($items) { foreach ($items as $item) { $prepared = prepare($item); if ($item->skip) { continue; } save($prepared); } } }; }', 1];
        yield 'arrow returning a closure checked once' => ['$callback = fn () => function ($items) { foreach ($items as $item) { $prepared = prepare($item); if ($item->skip) { continue; } save($prepared); } };', 1];
        yield 'separate closures retain both findings' => ['function outer() { register(function ($items) { foreach ($items as $item) { $prepared = prepare($item); if ($item->skip) { continue; } save($prepared); } }); register(function ($items) { foreach ($items as $item) { $prepared = prepare($item); if ($item->skip) { continue; } save($prepared); } }); }', 2];
        yield 'interface method without a body' => ['interface Contract { public function run(): void; }', 0];
        yield 'abstract method without a body' => ['abstract class Worker { abstract public function run(): void; }', 0];
        yield 'work skipped by the guard' => ['function run(bool $ready, array $items): void { $result = compute($items); if (!$ready) { return; } useIt($result); }', 1];
        yield 'throw guard' => ['function run(bool $ready): int { $result = compute(); if (!$ready) { throw new \RuntimeException("not ready"); } return $result; }', 1];
        yield 'continue guard inside a loop' => ['function run(array $items): void { foreach ($items as $item) { $prepared = prepare($item); if ($item->skip) { continue; } save($prepared); } }', 1];
        yield 'break guard inside a loop' => ['function run(array $items): void { while ($item = nextItem($items)) { $prepared = prepare($item); if ($item->done) { break; } save($prepared); } }', 1];
        yield 'value is read by the guard condition' => ['function run(bool $ready): int { $result = compute(); if ($result === null) { return 0; } return $result; }', 0];
        yield 'value is read before the guard' => ['function run(bool $ready): void { $result = compute(); audit($result); if (!$ready) { return; } useIt($result); }', 0];
        yield 'value is read inside the guarded branch' => ['function run(bool $ready): void { $result = compute(); if (!$ready) { logIt($result); return; } useIt($result); }', 0];
        yield 'value is not used after the guard' => ['function run(bool $ready): void { $result = compute(); if (!$ready) { return; } useOther(); }', 0];
        yield 'closure capture counts as a use' => ['function run(bool $ready): void { $result = compute(); register(function () use ($result) { save($result); }); if (!$ready) { return; } flush(); }', 0];
        yield 'assigned closure is a skipped declaration' => ['function run(bool $ready): void { $result = compute(); $task = function () use ($result) { save($result); }; if (!$ready) { return; } $task(); }', 1];
        yield 'branching guard keeps the path open' => ['function run(bool $ready): void { $result = compute(); if (!$ready) { return; } else { audit(); } useIt($result); }', 0];
        yield 'declaration belongs after the guard' => ['function run(bool $ready): void { if (!$ready) { return; } $result = compute(); useIt($result); }', 0];
        yield 'second guard is reported once' => ['function run(bool $ready, bool $done): void { $result = compute(); if (!$ready) { return; } if (!$done) { return; } useIt($result); }', 1];
        yield 'guarded branch with assignment stays outside' => ['function run(bool $ready): void { if (!$ready) { $result = compute(); return; } useOther(); }', 0];
    }

    public function testReportsTheVariableAndTheGuardLine(): void
    {
        $findings = $this->findings(<<<'PHP'
<?php

function run(bool $ready, array $items): int
{
    $result = compute($items);
    if (! $ready) {
        return 0;
    }

    return $result;
}
PHP);
        self::assertCount(1, $findings);
        self::assertSame('no_declarations_before_early_exit', $findings[0]->rule);
        self::assertSame(5, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('$result', $findings[0]->message);
        self::assertStringContainsString('line 6', $findings[0]->message);
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-declarations-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function run(bool $ready): int { $result = compute(); if (!$ready) { return 0; } return $result; }');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'no_declarations_before_early_exit')->values()->all();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    public function testStandardRunnerPreservesSnapshotsBeforeInputMutation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-guard-snapshots-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function snapshot(array $options, bool $ready) { $trial = $options["trial"] ?? null; unset($options["trial"]); if (!$ready) { return; } save($trial); } function movable(array $options, bool $ready) { $trial = $options["trial"] ?? null; if (!$ready) { return; } save($trial); }');

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_declarations_before_early_exit')->values();
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

        return iterator_to_array((new NoDeclarationsBeforeEarlyExit)->checkFile(new FileFacts('/declarations.php', $source, $nodes, [])), false);
    }
}
