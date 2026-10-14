<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoRedundantBooleanComparisons;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoRedundantBooleanComparisonsTest extends TestCase
{
    #[DataProvider('expressions')]
    public function testProvenBooleanBoundary(string $expression, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoRedundantBooleanComparisons, '<?php $result = '.$expression.';'));
    }

    public static function expressions(): array
    {
        return [
            ['($a === $b) === true', 1],
            ['($a < $b) == false', 1],
            ['($a != $b) !== false', 1],
            ['isset($value) === TRUE', 1],
            ['empty($value) != FALSE', 1],
            ['($value instanceof Task) === false', 1],
            ['(! $value) === true', 1],
            ['((bool) $value) == true', 1],
            ['($a && $b) === true', 1],
            ['($a || $b) !== false', 1],
            ['($a and $b) === true', 1],
            ['($a xor $b) == false', 1],
            ['false === ($a >= $b)', 1],
            ['true != isset($value)', 1],
            ['(isset($value) === false) === true', 2],
            ['fn () => isset($value) === true', 1],
            ['$value === true', 0],
            ['$value == false', 0],
            ['isReady() === true', 0],
            ['$task->isReady() !== false', 0],
            ['fopen("file", "r") === false', 0],
            ['strpos($value, "needle") === false', 0],
            ['($a <=> $b) === true', 0],
            ['($a & $b) == true', 0],
            ['($a + $b) == false', 0],
            ['($value ? true : false) === true', 0],
            ['isset($value) === 1', 0],
            ['isset($value) === "true"', 0],
            ['isset($value) === Other\true', 0],
            ['true === false', 0],
        ];
    }

    #[DataProvider('comparisons')]
    public function testReplacementAdvice(string $expression, bool $negated): void
    {
        $rule = new NoRedundantBooleanComparisons;
        $findings = FiveRuleFixture::findings($rule, "<?php\n\$result = ".$expression.';');
        self::assertCount(1, $findings);
        self::assertSame('no_redundant_boolean_comparisons', $findings[0]->rule);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString($negated ? '! (boolean expression)' : 'Use (boolean expression)', $findings[0]->message);
        self::assertStringContainsString('once', $findings[0]->message);
        self::assertSame([], $rule->requiredAnalyses());
        self::assertSame(NoRedundantBooleanComparisons::class, RuleCatalog::standardDefinitions()[$rule->id()]['class']);
    }

    public static function comparisons(): array
    {
        return [
            ['isset($value) === true', false],
            ['isset($value) !== true', true],
            ['isset($value) == false', true],
            ['isset($value) != false', false],
            ['false === isset($value)', true],
            ['false !== isset($value)', false],
            ['true == isset($value)', false],
            ['true != isset($value)', true],
        ];
    }

    public function testUnknownInputsNeedStrictBooleanChecks(): void
    {
        self::assertFalse(1 === true);
        self::assertTrue((bool) 1);
        self::assertFalse(0 === false);
        self::assertTrue(! 0);
    }

    #[DataProvider('booleans')]
    public function testProvenBooleanReplacementsPreserveEvaluation(bool $value): void
    {
        $calls = 0;
        $predicate = static function () use ($value, &$calls): bool {
            $calls++;

            return $value;
        };
        self::assertSame($value === true, (bool) $predicate());
        self::assertSame(1, $calls);
        $calls = 0;
        self::assertSame($value === false, ! ((bool) $predicate()));
        self::assertSame(1, $calls);
    }

    public static function booleans(): array
    {
        return [[false], [true]];
    }
}
