<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Architecture\NoSingletonAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoSingletonAccessTest extends TestCase
{
    #[DataProvider('accessors')]
    public function testRequiresSharedObjectStorage(string $declaration, string $call, int $count): void
    {
        $this->assertCount($count, $this->findings('<?php '.$declaration.' '.$call));
    }

    public static function accessors(): iterable
    {
        yield 'cached object' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } }', 'Registry::instance();', 1];
        yield 'case insensitive getter' => ['class Registry { private static $current; public static function GETINSTANCE() { return static::$current ??= new static; } }', 'Registry::getInstance();', 1];
        yield 'factory makes a fresh object' => ['class Value { public static function instance($value) { return new self($value); } }', 'Value::instance(10);', 0];
        yield 'factory clones a value' => ['class Value { public static function instance($value) { return clone $value; } }', 'Value::instance(new stdClass);', 0];
        yield 'unknown declaration' => ['', 'Missing::getInstance();', 0];
        yield 'dynamic receiver' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } }', '$type::instance();', 0];
        yield 'unexecuted first class callable' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } }', '$callback = Registry::instance(...);', 0];
        yield 'unrelated method' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } public static function fresh() { return new self; } }', 'Registry::fresh();', 0];
        yield 'local coalescing is not shared' => ['class Value { public static function instance($value = null) { return $value ??= new self; } }', 'Value::instance();', 0];
        yield 'scalar cache is not an object' => ['class Count { private static $current; public static function instance() { return self::$current ??= 10; } }', 'Count::instance();', 0];
        yield 'initialized scalar is not an object cache' => ['class Count { private static $current = 10; public static function instance() { return self::$current ??= new self; } }', 'Count::instance();', 0];
        yield 'nested body does not prove sharing' => ['class Value { private static $current; public static function instance() { return function () { return self::$current ??= new self; }; } }', 'Value::instance();', 0];
        yield 'undeclared storage' => ['class Missing { public static function instance() { return self::$current ??= new self; } }', 'Missing::instance();', 0];
        yield 'instance storage is not static' => ['class Missing { private $current; public static function instance() { return self::$current ??= new self; } }', 'Missing::instance();', 0];
        yield 'alias preserves the target' => ['namespace App; use App\Registry as Shared; class Registry { private static $current; public static function instance() { return self::$current ??= new self; } }', 'Shared::instance();', 1];
        yield 'self preserves lexical receiver' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } public static function run() { return self::instance(); } }', '', 1];
        yield 'parent preserves declared receiver' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } } class Child extends Registry { public static function run() { return parent::instance(); } }', '', 1];
        yield 'final static receiver is known' => ['final class Registry { private static $current; public static function instance() { return self::$current ??= new self; } public static function run() { return static::instance(); } }', '', 1];
        yield 'open static receiver can override' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } public static function run() { return static::instance(); } }', '', 0];
        yield 'dynamic property is unknown' => ['class Registry { private static $current; public static function instance($property) { return self::$$property ??= new self; } }', 'Registry::instance("current");', 0];
        yield 'foreign static slot is unknown' => ['class Registry { private static $current; public static function instance() { return Other::$current ??= new self; } }', 'Registry::instance();', 0];
        yield 'same short name has another namespace' => ['namespace First { class Registry { private static $current; public static function instance() { return self::$current ??= new self; } } } namespace Second { class Registry { public static function instance() { return new self; } } }', 'namespace Second { Registry::instance(); }', 0];
        yield 'anonymous class does not borrow enclosing receiver' => ['class Registry { private static $current; public static function instance() { return self::$current ??= new self; } public static function run() { return new class { function next() { return self::instance(); } }; } }', '', 0];
    }

    public function testStandardRunnerResolvesInheritanceTraitsAndFileOrder(): void
    {
        $declarations = <<<'PHP'
<?php
namespace App;
class Registry {
    private static $current;
    public static function instance() { return self::$current ??= new self; }
}
trait SharedState {
    private static $current;
    public static function instance() { return self::$current ??= new self; }
}
class Derived extends Registry {}
class Fresh extends Registry {
    public static function instance() { return new self; }
}
class FromTrait { use SharedState; }
PHP;
        $calls = <<<'PHP'
<?php
use App\Registry as Shared;
Shared::instance();
App\Derived::instance();
App\Fresh::instance();
App\FromTrait::instance();
Unknown::instance();
PHP;
        foreach ([false, true] as $reverse) {
            $findings = $this->findings($calls, $declarations, $reverse, RuleCatalog::standard());
            self::assertSame([3, 4, 6], array_column($findings, 'line'));
            self::assertSame([1, 1, 1], array_column($findings, 'value'));
            self::assertSame([0, 0, 0], array_column($findings, 'threshold'));
        }
    }

    public function testReusedRuleDoesNotRetainDeclarationsFromAnotherProject(): void
    {
        $rule = new NoSingletonAccess;
        self::assertCount(1, $this->findings('<?php Registry::instance();', '<?php class Registry { private static $current; public static function instance() { return self::$current ??= new self; } }', checks: [$rule]));
        self::assertSame([], $this->findings('<?php Registry::instance();', checks: [$rule]));
    }

    public function testDuplicateDeclarationsDoNotChooseAnImplementationByFileOrder(): void
    {
        $shared = '<?php class Registry { private static $current; public static function instance() { return self::$current ??= new self; } }';
        $fresh = '<?php class Registry { public static function instance() { return new self; } } Registry::instance();';
        foreach ([false, true] as $reverse) {
            self::assertSame([], $this->findings($fresh, $shared, $reverse));
        }
    }

    private function findings(string $source, ?string $declarations = null, bool $reverse = false, ?array $checks = null): array
    {
        $paths = [tempnam(sys_get_temp_dir(), 'soda-singleton-')];
        file_put_contents($paths[0], $source);
        if ($declarations !== null) {
            $paths[] = tempnam(sys_get_temp_dir(), 'soda-singleton-types-');
            file_put_contents($paths[1], $declarations);
        }

        try {
            $result = (new Runner)->check($reverse ? array_reverse($paths) : $paths, $checks ?? [new NoSingletonAccess]);

            return $result->violations->filter(static fn ($row): bool => $row->rule === 'no_singleton_access')->values()->all();
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }
}
