<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\PreferNullCoalesceAssignment;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
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
        yield 'array dimension' => ['function initialize(array $cache) { $cache["key"] = $cache["key"] ?? []; }', 1];
        yield 'nested property target stays outside' => ['final class Context { private string $scope = "value"; function initialize() { ${$this->scope} = ${$this->scope} ?? 1; } }', 0];
        yield 'declared property fetch' => ['final class Context { private ?string $scope = null; function initialize() { $this->scope = $this->scope ?? "web"; } }', 1];
        yield 'chained fallback' => ['$value = $value ?? $fallback ?? "draft";', 1];
        yield 'different target' => ['$value = $other ?? "draft";', 0];
        yield 'different key' => ['$cache["key"] = $cache["other"] ?? [];', 0];
        yield 'shorthand ternary stays outside' => ['$value = $value ?: "draft";', 0];
        yield 'plain assignment without fallback' => ['$value = $other ?? "draft";', 0];
        yield 'conditional on the right side' => ['$value = $value ?? compute();', 1];
        yield 'call on the left side stays outside' => ['$value = read($value) ?? "draft";', 0];
        yield 'target assigned through a call stays outside' => ['$cache[resolve()] = $cache[resolve()] ?? [];', 0];
        yield 'post increment changes the target index' => ['$cache[$index++] = $cache[$index++] ?? [];', 0];
        yield 'pre increment changes the target index' => ['$cache[++$index] = $cache[++$index] ?? [];', 0];
        yield 'post decrement changes the target index' => ['$cache[$index--] = $cache[$index--] ?? [];', 0];
        yield 'pre decrement changes the target index' => ['$cache[--$index] = $cache[--$index] ?? [];', 0];
        yield 'assignment inside a loop' => ['foreach ($rows as $row) { $total = $total ?? 0; }', 1];
        yield 'unknown property receiver may have a setter' => ['function initialize($record) { $record->scope = $record->scope ?? "web"; }', 0];
        yield 'magic property assignment is observable' => ['final class Context { function __set($key, $value) { logWrite($key, $value); } function initialize() { $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'unset declared property may use a magic setter' => ['final class Context { private ?string $scope = "web"; function __set($key, $value) { logWrite($key, $value); } function initialize() { unset($this->scope); $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'magic getter need not initialize an unset field' => ['final class Context { public ?string $scope = null; function __get($key) { return "virtual"; } function initialize() { unset($this->scope); $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'magic existence check may observe an unset field' => ['final class Context { public ?string $scope = null; function __isset($key) { return true; } function initialize() { unset($this->scope); $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'readonly assignment cannot be skipped' => ['final class Context { private readonly string $scope; function initialize() { $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'readonly class assignment cannot be skipped' => ['final readonly class Context { private string $scope; function initialize() { $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'property hook observes assignment' => ['final class Context { public string $scope { set { logWrite($value); $this->scope = $value; } } function initialize() { $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'subclass can change property behavior' => ['class Context { public ?string $scope = null; function initialize() { $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'parent may supply a magic setter' => ['final class Context extends ParentContext { public ?string $scope = null; function initialize() { $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'trait may supply a magic setter' => ['final class Context { use Attributes; public ?string $scope = null; function initialize() { $this->scope = $this->scope ?? "web"; } }', 0];
        yield 'property inside a rebound closure has a different receiver' => ['final class Context { private ?string $scope = null; function initialize() { return function () { $this->scope = $this->scope ?? "web"; }; } }', 0];
    }

    public function testReportsTheTargetWithAdvice(): void
    {
        $findings = $this->findings('<?php function initialize(array $cache) { $cache["key"] = $cache["key"] ?? []; }');
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

    public function testStandardRunnerPreservesObservablePropertyWrites(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-coalesce-properties-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function initialize($subscription) { $subscription->type = $subscription->type ?? "default"; $value = $value ?? "draft"; } final class Context { private ?string $scope = null; function initialize() { $this->scope = $this->scope ?? "web"; } }');

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($item): bool => $item->rule === 'prefer_null_coalesce_assignment')->values();
            self::assertCount(2, $findings);
            self::assertStringContainsString('$value', $findings[0]->message);
            self::assertStringContainsString('$this->scope', $findings[1]->message);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('arrayOffsets')]
    public function testStandardRunnerPreservesArrayOffsetWrites(string $source, int $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-coalesce-array-');
        $control = tempnam(sys_get_temp_dir(), 'soda-coalesce-native-');
        self::assertIsString($path);
        self::assertIsString($control);
        file_put_contents($path, '<?php '.$source);
        file_put_contents($control, '<?php function nativeArray(array $items) { $items["quantity"] = $items["quantity"] ?? 1; } function plainVariable($value) { $value = $value ?? 1; }');

        try {
            foreach ([[new PreferNullCoalesceAssignment], RuleCatalog::standard()] as $checks) {
                foreach ([[$path, $control], [$control, $path]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    $findings = $result->violations->filter(static fn ($item): bool => $item->rule === 'prefer_null_coalesce_assignment');
                    $target = $findings->filter(static fn ($item): bool => $item->file === $path)->values();
                    self::assertCount($expected, $target);
                    self::assertCount(2, $findings->filter(static fn ($item): bool => $item->file === $control));
                    self::assertCount(0, $result->violations->filter(static fn ($item): bool => $item->rule === 'parse_error'));
                    if ($expected === 1) {
                        self::assertSame(['rule' => 'prefer_null_coalesce_assignment', 'file' => $path, 'method' => null, 'class' => null, 'line' => 1, 'value' => 1, 'threshold' => 0, 'message' => "Assign the fallback with the ??= operator instead of repeating \$items['quantity'] on both sides."], $target[0]->toArray());
                    }
                }
            }
        } finally {
            unlink($path);
            unlink($control);
        }
    }

    public static function arrayOffsets(): iterable
    {
        yield 'native array parameter' => [<<<'PHP'
function target(array $items) { $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 1];
        yield 'local initializer is outside entry proof' => [<<<'PHP'
function target() { $items = []; $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'reference input initializer stays unsupported' => [<<<'PHP'
function target(&$items) { $items = []; $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'reference capture initializer stays unsupported' => [<<<'PHP'
function target(&$items) { return function () use (&$items) { $items = []; $items['quantity'] = $items['quantity'] ?? 1; }; }
PHP
            , 0];
        yield 'unknown receiver' => [<<<'PHP'
function target($items) { $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'native ArrayAccess receiver' => [<<<'PHP'
function target(\ArrayAccess $items) { $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'nested array element can be ArrayAccess' => [<<<'PHP'
function target(array $items) { $items[0]['quantity'] = $items[0]['quantity'] ?? 1; }
PHP
            , 0];
        yield 'outer array does not type callback item' => [<<<'PHP'
function target(array $rows) { return array_map(function ($items) { $items['quantity'] = $items['quantity'] ?? 1; return $items; }, $rows); }
PHP
            , 0];
        yield 'by reference array input' => [<<<'PHP'
function target(array &$items) { $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'replacement before target' => [<<<'PHP'
function target(array $items) { $items = other(); $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'reference alias before target' => [<<<'PHP'
function target(array $items) { $alias =& $items; $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'branch before target' => [<<<'PHP'
function target(array $items) { if (choose()) { $items = other(); } $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'capture before target' => [<<<'PHP'
function target(array $items) { $callback = function () use (&$items) { $items = other(); }; $items['quantity'] = $items['quantity'] ?? 1; }
PHP
            , 0];
        yield 'loop binding replaces input' => [<<<'PHP'
function target(array $items) { foreach (rows() as $items) { $items['quantity'] = $items['quantity'] ?? 1; } }
PHP
            , 0];
        yield 'nested callable parameter shadows array' => [<<<'PHP'
function target(array $items) { return function ($items) { $items['quantity'] = $items['quantity'] ?? 1; }; }
PHP
            , 0];
        yield 'float key conversion is observable' => [<<<'PHP'
function target(array $items) { $items[1.5] = $items[1.5] ?? 1; }
PHP
            , 0];
        yield 'computed key unsupported' => [<<<'PHP'
function target(array $items, $key) { $items[$key] = $items[$key] ?? 1; }
PHP
            , 0];
        yield 'unknown fallback offset can execute code' => [<<<'PHP'
function target(array $items, $fallback) { $items['quantity'] = $items['quantity'] ?? $fallback[0]; }
PHP
            , 0];
        yield 'suspending fallback unsupported' => [<<<'PHP'
function target(array $items) { $items['quantity'] = $items['quantity'] ?? yield 1; }
PHP
            , 0];
    }

    private function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $nodes = (new NodeTraverser(new ParentConnectingVisitor))->traverse($nodes);

        return iterator_to_array((new PreferNullCoalesceAssignment)->checkFile(new FileFacts('/coalesce.php', $source, $nodes, [])));
    }
}
