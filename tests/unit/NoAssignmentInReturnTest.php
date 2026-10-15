<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoAssignmentInReturn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoAssignmentInReturnTest extends TestCase
{
    #[DataProvider('keys')]
    public function testClassNameKeysRespectExistingReturnAllowances(string $key, int $count): void
    {
        foreach (['return self::$cache['.$key.'] ??= 42;', 'return self::$cache['.$key.'] = 42;', 'return fn () => self::$cache['.$key.'] = 42;'] as $body) {
            $source = '<?php namespace App; use External\Missing as Alias; class Base {} class Store extends Base { private static array $cache = []; public function value($class, $field) { '.$body.' } }';
            self::assertCount($count, FiveRuleFixture::findings(new NoAssignmentInReturn, $source));
        }
    }

    public static function keys(): iterable
    {
        yield 'self class name' => ['self::class', 0];
        yield 'late bound class name' => ['static::class', 0];
        yield 'parent class name' => ['parent::class', 0];
        yield 'uppercase keyword' => ['self::CLASS', 0];
        yield 'mixed-case keyword' => ['static::ClAsS', 0];
        yield 'qualified missing type' => ['\External\Missing::class', 0];
        yield 'imported class alias' => ['Alias::class', 0];
        yield 'ordinary class constant' => ['\External\Missing::KEY', 1];
        yield 'dynamic constant name' => ['self::{$field}', 1];
        yield 'dynamic class variable' => ['$class::class', 1];
        yield 'effectful class expression' => ['factory()::class', 1];
        yield 'constructed class expression' => ['(new \stdClass)::class', 1];
        yield 'assignment in the key' => ['($class = self::class)', 1];
        yield 'ordinary literal control' => ['"cache"', 0];
    }

    public function testClassNameKeysDoNotPermitFurtherOrReferenceAssignments(): void
    {
        foreach (['return self::$cache[static::class] ??= $value = 42;', 'return self::$cache[static::class] = $value = 42;', 'return self::$cache[static::class] =& $value;', 'return self::$cache[] = 42;'] as $body) {
            $source = '<?php class Store { private static array $cache = []; public function value($value) { '.$body.' } }';
            self::assertCount(1, FiveRuleFixture::findings(new NoAssignmentInReturn, $source));
        }
    }

    public function testClassNameKeysKeepConfiguredPolicyBoundaries(): void
    {
        $source = '<?php class Store { private static array $cache = []; public function value() { return self::$cache[static::class] ??= 42; } }';
        self::assertCount(1, FiveRuleFixture::findings(new NoAssignmentInReturn(allowLazyInitialization: false), $source));
        $source = '<?php class Store { private static array $cache = []; public function value() { return self::$cache[static::class] = 42; } }';
        self::assertCount(1, FiveRuleFixture::findings(new NoAssignmentInReturn(allowOwnerStateReturn: false), $source));
        $source = '<?php class Store { private static array $cache = []; public function value() { return fn () => self::$cache[static::class] = 42; } }';
        self::assertCount(1, FiveRuleFixture::findings(new NoAssignmentInReturn(allowArrowStateUpdates: false), $source));
    }

    public function testClassNameLiteralsNeedNoAutoloading(): void
    {
        $attempts = [];
        $autoload = static function (string $class) use (&$attempts): void {
            if ($class === 'SodaClassLiteralAudit\\Missing') {
                $attempts[] = $class;
            }
        };
        spl_autoload_register($autoload);

        try {
            $source = '<?php class Store { private static array $cache = []; public function value() { return self::$cache[\\SodaClassLiteralAudit\\Missing::class] ??= 42; } }';
            self::assertSame([], FiveRuleFixture::findings(new NoAssignmentInReturn, $source));
            self::assertSame([], $attempts);
        } finally {
            spl_autoload_unregister($autoload);
        }
    }

    public function testStandardRunnerRetainsClassCachesAndReportsALocalWrite(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-return-class-');
        self::assertIsString($path);
        file_put_contents($path, <<<'PHP'
<?php
class CacheBase
{
    private static array $values = [];
    public static function value(): int
    {
        return self::$values[static::class] ??= 42;
    }
}
class CacheChild extends CacheBase {}
function genuine(int $value): int
{
    return $value = 42;
}
PHP);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_assignment_in_return')->values();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
            self::assertSame(13, $findings[0]->line);
        } finally {
            unlink($path);
        }
    }
}
