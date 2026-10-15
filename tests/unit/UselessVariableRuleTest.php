<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Usage\UselessVariableAnalyzer;
use PHPUnit\Framework\TestCase;

final class UselessVariableRuleTest extends TestCase
{
    use ParsesPhpSnippets;

    private UselessVariableAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new UselessVariableAnalyzer;
    }

    /** @return list<array{line: int, variable: string, source: string}> */
    private function violations(string $code): array
    {
        return $this->analyzer->analyze($this->parseSnippet($code));
    }

    private function hasViolation(string $code): bool
    {
        return $this->violations($code) !== [];
    }

    // -------------------------------------------------------------------------
    // Spec — Cases that MUST produce a violation (true)
    // -------------------------------------------------------------------------

    public function testIndependentUpdateValuesSurviveRemovingPrimaryKeys(): void
    {
        $this->assertSame([], $this->violations(<<<'PHP'
class RecordRestorer {
    private function writeSandboxRow(array $attributes, array $keyColumns): array {
        $values = $attributes;
        foreach ($keyColumns as $keyColumn) {
            unset($values[$keyColumn]);
        }
        return $values;
    }
}
PHP));
    }

    /** Case 1 — simple alias: $a = $b; return $a; */
    public function testCase1SimpleAlias(): void
    {
        $this->assertTrue($this->hasViolation(
            'function test($b) { $a = $b; return $a; }',
        ));
    }

    public function testLoopBindingsAndDestructuringKeepIndependentValues(): void
    {
        foreach ([
            'function bind($abstract, $types) { $concrete = $abstract; foreach ($types as $abstract) { bind($abstract, $concrete); } }',
            'function keys($key, $items) { $original = $key; foreach ($items as $key => $value) { consume($key, $original); } }',
            'function scan($cursor) { $initial = $cursor; do { [$cursor, $batch] = scan($cursor); } while ($cursor !== $initial); }',
            'function scan($cursor) { $initial = $cursor; list($cursor, $batch) = scan($cursor); return $initial; }',
            'function read($input, $rows) { $original = $input; foreach ($rows as [$input]) { consume($original); } }',
            'function read($input) { $original = $input; [[$input]] = rows(); return $original; }',
            'function recover($error) { $original = $error; try { attempt(); } catch (Exception $error) { consume($original); } }',
        ] as $source) {
            self::assertSame([], $this->violations($source), $source);
        }

        self::assertTrue($this->hasViolation('function values($items) { $copy = $items; foreach ($items as $item) { consume($copy, $item); } }'));
        self::assertTrue($this->hasViolation('function indexes($index, $rows) { $copy = $index; [$rows[$index]] = values(); return $copy; }'));
    }

    public function testStandardRunnerPreservesLoopSnapshotsAndStillDetectsAliases(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-alias-mutations-');
        self::assertIsString($path);
        file_put_contents($path, <<<'PHP'
<?php
function bind($abstract, $types) { $concrete = $abstract; foreach ($types as $abstract) { bind($abstract, $concrete); } }
function scan($cursor) { $initial = $cursor; do { [$cursor, $batch] = scan($cursor); } while ($cursor !== $initial); }
function alias($input) { $copy = $input; return $copy; }
PHP);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'useless_variable')->values();
            self::assertCount(1, $findings);
            self::assertSame(4, $findings[0]->line);
        } finally {
            unlink($path);
        }
    }

    public function testIncludedFilesAndSymbolTablesCanObserveAliasNames(): void
    {
        foreach ([
            'require $path;',
            'include_once $path;',
            'eval($code);',
            'consume(get_defined_vars());',
            'consume(compact("factory"));',
            'consume($$name);',
        ] as $statement) {
            self::assertSame([], $this->violations('function load($path) { $factory = $this; '.$statement.' return $factory; }'), $statement);
        }

        self::assertTrue($this->hasViolation('function load($path) { $factory = $this; consume($path); return $factory; }'));
    }

    /** Case 2 — alias passed to a function: $a = $b; foo($a); */
    public function testCase2AliasInCall(): void
    {
        $this->assertTrue($this->hasViolation(
            'function test($b) { $a = $b; foo($a); }',
        ));
    }

    /** Case 3 — chain alias: $a = $b; $c = $a; return $c; */
    public function testCase3ChainAlias(): void
    {
        $this->assertTrue($this->hasViolation(
            'function test($b) { $a = $b; $c = $a; return $c; }',
        ));
    }

    // -------------------------------------------------------------------------
    // Spec — Cases that must NOT produce a violation (false)
    // -------------------------------------------------------------------------

    /** Case 4 — transformation: $a = trim($b) is not a direct copy */
    public function testCase4Transformation(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = trim($b); return $a; }',
        ));
    }

    /** Case 5 — mutation: $a++ after assignment */
    public function testCase5Mutation(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; $a++; return $a; }',
        ));
    }

    /** Case 6 — reassignment: $a = 10 after $a = $b */
    public function testCase6Reassignment(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; $a = 10; return $a; }',
        ));
    }

    /** Case 7 — passed by reference: foo(&$a) */
    public function testCase7Reference(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; foo(&$a); }',
        ));
    }

    /** Case 8 — object mutation: $a->x = 1 */
    public function testCase8ObjectMutation(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($obj) { $a = $obj; $a->x = 1; }',
        ));
    }

    /** Case 9 — source unset: unset($b) before $a is used */
    public function testCase9UnsetSource(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; unset($b); return $a; }',
        ));
    }

    /** Case 10 — closure capture: fn() => $a */
    public function testCase10Closure(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; return fn() => $a; }',
        ));
    }

    // -------------------------------------------------------------------------
    // Violation metadata
    // -------------------------------------------------------------------------

    public function testViolationContainsLineVariableAndSource(): void
    {
        $violations = $this->violations(
            'function test($b) { $a = $b; return $a; }',
        );

        $this->assertCount(1, $violations);
        $this->assertSame('$a', $violations[0]['variable']);
        $this->assertSame('$b', $violations[0]['source']);
        $this->assertIsInt($violations[0]['line']);
        $this->assertGreaterThan(0, $violations[0]['line']);
    }

    // -------------------------------------------------------------------------
    // Scope behaviour
    // -------------------------------------------------------------------------

    /** Top-level code is not analyzed — only function/method bodies. */
    public function testTopLevelCodeIsIgnored(): void
    {
        $this->assertFalse($this->hasViolation('$a = $b; return $a;'));
    }

    /** Class methods are analyzed. */
    public function testClassMethodIsAnalyzed(): void
    {
        $this->assertTrue($this->hasViolation(
            'class Foo { public function test($b) { $a = $b; return $a; } }',
        ));
    }

    /** Multiple useless variables in one scope are each reported. */
    public function testMultipleUselessVariablesInOneScope(): void
    {
        $violations = $this->violations(
            'function test($b) { $a = $b; $c = $b; return $a + $c; }',
        );

        $this->assertCount(2, $violations);
    }

    /** Regular closure use($a) prevents detection. */
    public function testRegularClosureCapturePreventsDetection(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; $fn = function() use ($a) { return $a; }; return $fn; }',
        ));
    }

    /** Compound assignment ($a += 1) counts as mutation. */
    public function testCompoundAssignmentIsMutation(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; $a += 1; return $a; }',
        ));
    }

    /** Variable never used after assignment — no violation (nothing to replace). */
    public function testUnusedVariableIsNotFlagged(): void
    {
        $this->assertFalse($this->hasViolation(
            'function test($b) { $a = $b; }',
        ));
    }
}
