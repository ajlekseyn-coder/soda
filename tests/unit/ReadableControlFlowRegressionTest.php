<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\AstFacts;
use Cosmira\Soda\Analysis\LogicalLineMap;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoComplexControlConditions;
use Cosmira\Soda\Rules\Complexity\NoElseBranches;
use Cosmira\Soda\Rules\Complexity\PreferContinue;
use Cosmira\Soda\Rules\Usage\NoTemporaryReturnVariable;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadableControlFlowRegressionTest extends TestCase
{
    #[DataProvider('conditions')]
    public function testConditionsNeedGroupingRatherThanTemporaryNames(string $condition, int $count): void
    {
        self::assertCount($count, $this->findings('if ('.$condition.') {}', [new NoComplexControlConditions]));
    }

    public static function conditions(): iterable
    {
        foreach ([
            '$data->parentGroups()->isNotEmpty()', '$resourceList->perimeterEntries()->exists()',
            '$user->hasRole(config("auth.admin_role"))', '$query->whereIn("group_id", $user->groupIds())->exists()',
            '$a && ($b || $c)', '($value = $a && $b) || $c', '!($value = $a && $b) || $c',
            '(bool)($value = $a && $b) || $c', '(bool)(!($value = $a && $b)) || $c',
            '!!($value = $a && $b) || $c', '$a && !($value = $b || $c)', '($a && ($b)) || $c', '($a && $b) || $c', '!($a || ($b && $c))', '$a xor $b',
            '$a and ($b || $c)', '$a && /* misleading ) */ ($b || $c)',
            '$a && (/* ( */ $b || $c /* ) */)', 'check("(", nested(work()))',
            '($ready ? one() : two()) === 1', '(match ($value) { 1 => true, default => false })',
        ] as $condition) {
            yield $condition => [$condition, 0];
        }
        foreach (['$a && $b || $c', '$a && ($b) || $c', '($a && $b || $c)', '$a && /* ( */ $b || $c /* ) */', '$a or $b xor $c', '!($value = $a && $b || $c) || $d'] as $condition) {
            yield $condition => [$condition, 1];
        }
    }

    public function testNaturalWhilePredicatesAndCallbackConditionsHaveTheirOwnScope(): void
    {
        self::assertSame([], $this->findings('while (query()->where("name", trim($name))->exists()) { $name = nextName(); }', [new NoComplexControlConditions]));
        self::assertCount(1, $this->findings('if (check(function () { if ($a && $b || $c) {} })) {}', [new NoComplexControlConditions]));
    }

    public function testMissingSourcePositionsCannotProveMissingGrouping(): void
    {
        $source = '<?php if ($a && $b || $c) {}';
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $metrics = AstFacts::collect($nodes, LogicalLineMap::fromSource($source), ['conditions']);
        self::assertSame([], $metrics['complexControlConditions']);
    }

    #[DataProvider('branches')]
    public function testOnlyRedundantAlternativesAreReported(string $body, int $count): void
    {
        self::assertCount($count, $this->findings('function run() { '.$body.' }', [new NoElseBranches]));
    }

    public static function branches(): iterable
    {
        yield ['if ($driver === "pgsql") { pg(); } elseif ($driver === "mysql") { mysql(); } else { other(); }', 0];
        yield ['if ($a) { work(); } elseif ($b) { return; } else { rest(); }', 0];
        yield ['if ($a) { return; } else { rest(); }', 1];
        yield ['if ($a) { if ($b) { return; } else { throw new Exception; } } else { rest(); }', 2];
        yield ['if ($a) { foreach ($items as $item) { break; } } else { rest(); }', 0];
        yield ['try { if ($a) { throw new Exception; } else { rest(); } } catch (Exception $e) {}', 1];
    }

    #[DataProvider('iterations')]
    public function testContinueOnlyFlattensNestedExecutableFlow(string $body, int $count): void
    {
        self::assertCount($count, $this->findings('function run($items) { foreach ($items as $item) { '.$body.' } }', [new PreferContinue]));
    }

    public static function iterations(): iterable
    {
        yield ['mark($item); if ($item) { store($item); index($item); audit($item); countIt(); finish(); }', 0];
        yield ['if ($item) { consume(function () { if (ready()) { work(); } }); }', 0];
        yield ['if ($item) { if (fresh()) { store($item); } }', 1];
        yield ['if ($item) { foreach (parts() as $part) { store($part); } }', 1];
        yield ['if ($item) { if (fresh()) { return 1; } else { return 2; } }', 0];
        yield ['if ($item) { try { work(); } finally { cleanup(); } }', 0];
        yield ['if ($item) { goto done; } done: finish();', 0];
        yield ['if (!$item) { continue; } store($item);', 0];
        yield ['if ($item) { switch ($item) { case 1: continue 2; default: store($item); } }', 1];
        yield ['if ($item) { foreach (parts() as $part) { if ($part) { continue 2; } store($part); } }', 1];
    }

    #[DataProvider('typedReturns')]
    public function testReturnVariablesPreserveTypesAndObservableBindings(string $body, int $count): void
    {
        self::assertCount($count, $this->findings('function run() { '.$body.' }', [new NoTemporaryReturnVariable]));
    }

    public static function typedReturns(): iterable
    {
        yield ['/** @var array<string, mixed> $config */ $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR); return $config;', 0];
        yield ['/** @var array<string, mixed> */ $config = decode(); return $config;', 0];
        yield ['/** @var string $other */ $config = decode(); return $config;', 1];
        yield ['/** The decoded configuration. */ $config = decode(); return $config;', 1];
        yield ['/** @var */ $config = decode(); return $config;', 1];
        yield ['try { $result = compute(); return $result; } finally { audit(compact("result")); }', 0];
        yield ['$result = compute(); return $result;', 1];
        yield ["/** @var array{'foo': int} \$result */ \$result = compute(); return \$result;", 0];
        yield ['/** @var callable(int $input): string $result */ $result = compute(); return $result;', 0];
        yield ["/** @var 'ok' \$result */ \$result = compute(); return \$result;", 0];
        yield ['/** @var 42 $result */ $result = compute(); return $result;', 0];
        yield ['/** @var "hello @world" $other */ $result = compute(); return $result;', 1];
        yield ['/** @var "$result" $other */ $result = compute(); return $result;', 1];
        yield ['/** @var "$foo" $result */ $result = compute(); return $result;', 0];
        yield ['/** @var $this $result */ $result = compute(); return $result;', 0];
        yield ['/** @var callable(): $this $result */ $result = compute(); return $result;', 0];
        yield ['/** @var list<array<string, int>> $result */ $result = compute(); return $result;', 0];
        yield ['/**
 * @var array{
 *   foo: int,
 *   bar: string
 * } $result
 */
$result = compute(); return $result;', 0];
        yield ['/** @var string $résultat */ $résultat = compute(); return $résultat;', 0];
        yield ['/** @var array< */ $result = compute(); return $result;', 1];
        yield ['/** @var $result */ $result = compute(); return $result;', 1];
    }

    public function testReturnLimitRemainsExplicitRatherThanPartOfTheStandardGate(): void
    {
        self::assertArrayHasKey('max_return_statements', RuleCatalog::definitions());
        self::assertNotContains('max_return_statements', array_map(fn ($rule) => $rule->id(), RuleCatalog::standard()));
    }

    #[DataProvider('readableExamples')]
    public function testStandardRulesComposeWithoutForcingAliasesOrExtraExits(string $source): void
    {
        $findings = $this->findings($source, RuleCatalog::standard());
        $relevant = array_filter($findings, fn ($finding) => in_array($finding->rule, [
            'no_complex_control_conditions', 'no_else_branches', 'prefer_continue',
            'no_temporary_return_variable', 'max_return_statements',
        ], true));
        self::assertSame([], array_values($relevant));
    }

    public static function readableExamples(): iterable
    {
        yield ['function run($data) { if ($data->parentGroups()->isNotEmpty()) { attach(); } }'];
        yield ['function run($name) { while (query()->where("name", trim($name))->exists()) { $name = nextName(); } }'];
        yield ['function run($items) { foreach ($items as $item) { if ($item) { store($item); index($item); } } }'];
        yield ['function run($items) { foreach ($items as $item) { if (!$item) { continue; } store($item); } }'];
        yield ['function run($driver) { if ($driver === "pgsql") { pg(); } elseif ($driver === "mysql") { mysql(); } }'];
        yield ['function run($content) { try { /** @var array<string, mixed> $config */ $config = json_decode($content, true, 512, JSON_THROW_ON_ERROR); return $config; } catch (JsonException $e) { throw new RuntimeException("Invalid JSON", 0, $e); } }'];
        yield ['function run($identity, $type, $requested) { if ($requested === false) { return false; } if (!ContactType::isPrimaryAllowed($type)) { return false; } return !query()->where("identity", $identity)->exists(); }'];
        yield ['function run($a, $b, $c, $d, $e) { if ($a) return 1; if ($b) return 2; if ($c) return 3; if ($d) return 4; if ($e) return 5; return 6; }'];
    }

    private function findings(string $source, array $rules): array
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-readable-');
        file_put_contents($path, '<?php '.$source);

        try {
            return CheckFixture::collect($path, $rules)['violations'];
        } finally {
            unlink($path);
        }
    }
}
