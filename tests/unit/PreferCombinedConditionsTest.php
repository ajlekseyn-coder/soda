<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\PreferCombinedConditions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreferCombinedConditionsTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testStructuralBoundaries(string $body, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new PreferCombinedConditions, '<?php function run() { '.$body.' }'));
    }

    public static function bodies(): array
    {
        return [
            ['if ($ready) { if ($valid) { save(); } }', 1],
            ['if (ready()) { if (valid()) { return; } }', 1],
            ['if ($size > 1) { if ($name !== "") { save(); } }', 1],
            ['if (! ready()) { if (isset($value)) { save(); } }', 1],
            ['if ($ready) if ($valid) save();', 1],
            ['if ($a) { if ($b) { if ($c) { save(); } } }', 2],
            ['$later = function () { if ($a) { if ($b) { save(); } } };', 1],
            ['while (next()) { if ($a) { if ($b) { break; } } }', 1],
            ['if ($a) { if ($b) { save(); } else { cancel(); } }', 0],
            ['if ($a) { if ($b) { save(); } } else { cancel(); }', 0],
            ['if ($a) { if ($b) { save(); } elseif ($c) { cancel(); } }', 0],
            ['if ($a) { if ($b) { save(); } } elseif ($c) { cancel(); }', 0],
            ['if ($a) { prepare(); if ($b) { save(); } }', 0],
            ['if ($a) { if ($b) { save(); } cleanup(); }', 0],
            ["if (\$a) {\n// Permission is checked separately for auditing.\nif (\$b) { save(); } }", 0],
            ['if ($a) { if ($b) {} }', 0],
            ['if ($a && $b) { if ($c) { save(); } }', 0],
            ['if ($a) { if ($b || $c) { save(); } }', 0],
            ['if (! ($a && $b)) { if ($c) { save(); } }', 0],
            ['if ($a) { if (normalize(value())) { save(); } }', 0],
            ['if ($a + 1) { if ($b) { save(); } }', 0],
            ['if ($a) { if ($b ? $c : $d) { save(); } }', 0],
            ['if ($a) { $later = function () { if ($b) { save(); } }; }', 0],
        ];
    }

    public function testSourceLocationAndCatalog(): void
    {
        $rule = new PreferCombinedConditions;
        $findings = FiveRuleFixture::findings($rule, "<?php\nif (ready()) {\n    if (valid()) { save(); }\n}");
        self::assertCount(1, $findings);
        self::assertSame('prefer_combined_conditions', $findings[0]->rule);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('(outer) && (inner)', $findings[0]->message);
        self::assertStringContainsString('order', $findings[0]->message);
        self::assertSame([], $rule->requiredAnalyses());
        self::assertSame(PreferCombinedConditions::class, RuleCatalog::standardDefinitions()[$rule->id()]['class']);
    }

    #[DataProvider('values')]
    public function testShortCircuitAndEvaluationOrder(mixed $outer, mixed $inner): void
    {
        $events = [];
        $first = static function () use ($outer, &$events) {
            $events[] = 'outer';

            return $outer;
        };
        $second = static function () use ($inner, &$events) {
            $events[] = 'inner';

            return $inner;
        };
        if ($first()) {
            if ($second()) {
                $events[] = 'body';
            }
        }

        $expected = $events;
        $events = [];
        if (($first()) && ($second())) {
            $events[] = 'body';
        }

        self::assertSame($expected, $events);
    }

    public static function values(): array
    {
        return [[false, true], [true, false], [true, true], ['0', true], ['false', 1], [[], [1]], [[1], '0']];
    }
}
