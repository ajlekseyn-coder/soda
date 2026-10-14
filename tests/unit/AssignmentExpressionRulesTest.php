<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Config\SodaInitFileEmitter;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Rules\Complexity\NoAssignmentInReturn;
use Cosmira\Soda\Rules\Complexity\NoChainedAssignments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssignmentExpressionRulesTest extends TestCase
{
    #[DataProvider('returnExpressions')]
    public function testReturnBoundaries(string $body, int $count): void
    {
        self::assertCount($count, $this->findings(new NoAssignmentInReturn, '<?php function work() { '.$body.' }'));
    }

    public static function returnExpressions(): array
    {
        return [
            'ordinary assignment'                => ['return $value = calculate();', 1],
            'compound assignment'                => ['return $value += calculate();', 1],
            'coalescing assignment'              => ['return $value ??= calculate();', 0],
            'reference assignment'               => ['return $value =& $other;', 1],
            'property write'                     => ['return $this->value = calculate();', 0],
            'owner array write'                  => ['return $this->items[$key] = calculate();', 0],
            'owner static array write'           => ['return self::$items[$key] = calculate();', 0],
            'nested owner array write'           => ['return $this->items["group"][$key] = calculate();', 0],
            'owner array lazy cache'             => ['return $this->items["bookworm"] ??= calculate();', 0],
            'local array lazy cache'             => ['return $items[$key] ??= calculate();', 0],
            'enum cache key'                     => ['return $this->items[$service->value] = calculate();', 0],
            'foreign static array write'         => ['return Other::$items[$key] = calculate();', 1],
            'foreign instance array write'       => ['return $other->items[$key] = calculate();', 1],
            'array append'                       => ['return $this->items[] = calculate();', 1],
            'dynamic cache key'                  => ['return $this->items[key()] ??= calculate();', 1],
            'assignment in cache key'            => ['return $this->items[$key = nextKey()] ??= calculate();', 1],
            'assignment in cache value'          => ['return $this->items[$key] ??= $value = calculate();', 1],
            'cache on called receiver'           => ['return cache()->items[$key] ??= calculate();', 1],
            'dynamic cache property'             => ['return $this->{$name}[$key] ??= calculate();', 1],
            'array write'                        => ['return $values[0] = calculate();', 1],
            'destructuring'                      => ['return [$first, $second] = calculate();', 1],
            'parentheses'                        => ['return ($value = calculate());', 1],
            'argument'                           => ['return consume($value = calculate());', 1],
            'ternary branch'                     => ['return $ready ? ($value = calculate()) : null;', 1],
            'match result'                       => ['return match ($key) { 1 => $value = calculate(), default => null };', 1],
            'multiple assignments reported once' => ['return consume($a = first(), $b = second());', 1],
            'arrow return'                       => ['return fn () => $value = calculate();', 1],
            'nested closure own return'          => ['return function () { return $value = calculate(); };', 1],
            'deferred closure write'             => ['return function () { $value = calculate(); };', 0],
            'closure in returned argument'       => ['return consume(function () { $value = calculate(); });', 0],
            'anonymous class state'              => ['return new class { function run() { $value = calculate(); } };', 0],
            'anonymous class own return'         => ['return new class { function run() { return $value = calculate(); } };', 1],
            'anonymous constructor argument'     => ['return new class($value = calculate()) {};', 1],
            'separate assignment'                => ['$value = calculate(); return $value;', 0],
            'plain expression'                   => ['return calculate();', 0],
            'bare return'                        => ['return;', 0],
            'comparison'                         => ['return $value === calculate();', 0],
            'increment is separate policy'       => ['return ++$value;', 0],
        ];
    }

    public function testArrayCachesRespectDisabledExceptions(): void
    {
        $source = '<?php function cached() { return $this->items[$key] ??= calculate(); }';
        self::assertCount(1, $this->findings(new NoAssignmentInReturn(allowLazyInitialization: false), $source));

        $source = '<?php function cached() { return $this->items[$key] = calculate(); }';
        self::assertCount(1, $this->findings(new NoAssignmentInReturn(allowOwnerStateReturn: false), $source));
    }

    #[DataProvider('assignmentChains')]
    public function testChainBoundaries(string $body, int $count): void
    {
        self::assertCount($count, $this->findings(new NoChainedAssignments, '<?php '.$body));
    }

    public static function assignmentChains(): array
    {
        return [
            'two assignments'                   => ['$a = $b = calculate();', 1],
            'three reported once'               => ['$a = $b = $c = calculate();', 1],
            'parentheses'                       => ['$a = ($b = calculate());', 1],
            'compound outer'                    => ['$a += $b = calculate();', 1],
            'compound inner'                    => ['$a = $b += calculate();', 1],
            'coalescing'                        => ['$a ??= $b ??= calculate();', 1],
            'reference inner'                   => ['$a = $b =& $c;', 1],
            'property and array'                => ['$this->value = $values[0] = calculate();', 1],
            'destructuring'                     => ['[$a, $b] = $value = calculate();', 1],
            'return chain'                      => ['function run() { return $a = $b = calculate(); }', 1],
            'arrow chain'                       => ['$callback = fn () => $a = $b = calculate();', 1],
            'closure chain'                     => ['$callback = function () { $a = $b = calculate(); };', 1],
            'two independent chains'            => ['$a = $b = first(); $c = $d = second();', 2],
            'separate assignments'              => ['$a = calculate(); $b = $a;', 0],
            'ordinary compound'                 => ['$a += calculate();', 0],
            'separate call argument assignment' => ['$a = consume($b = calculate());', 0],
            'separate ternary assignment'       => ['$a = $ready ? ($b = calculate()) : null;', 0],
            'comparison'                        => ['$a = $b === calculate();', 0],
        ];
    }

    public function testDiagnosticLocationsAndMetadata(): void
    {
        $source = "<?php\nfunction run() {\n    return \$a =\n        \$b = calculate();\n}";
        foreach ([new NoAssignmentInReturn, new NoChainedAssignments] as $rule) {
            $findings = $this->findings($rule, $source);
            self::assertCount(1, $findings);
            self::assertSame($rule->id(), $findings[0]->rule);
            self::assertSame(3, $findings[0]->line);
            self::assertSame(1, $findings[0]->value);
            self::assertSame(0, $findings[0]->threshold);
            self::assertStringContainsString('reference semantics', $findings[0]->message);
        }
    }

    public function testNestedClosureIsReportedAtItsOwnReturn(): void
    {
        $source = "<?php\nfunction run() {\n    return function () {\n        return \$value = calculate();\n    };\n}";
        $findings = $this->findings(new NoAssignmentInReturn, $source);
        self::assertCount(1, $findings);
        self::assertSame(4, $findings[0]->line);
    }

    public function testStandardRunnerAndInitIncludeBothRules(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-assignment-');
        file_put_contents($path, '<?php function run() { return $a = $b = calculate(); }');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            foreach (['no_assignment_in_return', 'no_chained_assignments'] as $id) {
                self::assertArrayHasKey($id, RuleCatalog::standardDefinitions());
                $findings = $result->violations->filter(fn ($finding): bool => $finding->rule === $id)->values()->all();
                self::assertCount(1, $findings);
                self::assertSame($path, $findings[0]->toArray()['file']);
                self::assertSame(1, $findings[0]->toArray()['line']);
            }
            $init = SodaInitFileEmitter::emit();
            self::assertStringContainsString('new NoAssignmentInReturn()', $init);
            self::assertStringContainsString('new NoChainedAssignments()', $init);
        } finally {
            unlink($path);
        }
    }

    private function findings(Check $rule, string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-assignment-');
        file_put_contents($path, $source);

        try {
            $facts = (new FactCollector)->collect($path, $rule->requiredAnalyses());

            return iterator_to_array($rule->checkFile($facts));
        } finally {
            unlink($path);
        }
    }
}
