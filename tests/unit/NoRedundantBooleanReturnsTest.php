<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoRedundantBooleanReturns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoRedundantBooleanReturnsTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testReturnBoundaries(string $body, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoRedundantBooleanReturns, '<?php function run() { '.$body.' }'));
    }

    public static function bodies(): array
    {
        return [
            ['if (ready()) { return true; } else { return false; }', 1],
            ['if ($value) { return false; } else { return true; }', 1],
            ['if ($value) { return TRUE; } return FALSE;', 1],
            ['if ($value) return false; return true;', 1],
            ['while (next()) { if ($value) { return true; } return false; }', 1],
            ['$later = function () { if (ready()) { return true; } return false; };', 1],
            ['$task = new class { function run() { if (ready()) { return true; } return false; } };', 1],
            ['if ($value) { return true; } else { return true; }', 0],
            ['if ($value) { return false; } return false;', 0],
            ['if ($value) { return 1; } return 0;', 0],
            ['if ($value) { return "true"; } return "false";', 0],
            ['if ($value) { return Other\true; } return false;', 0],
            ['if ($value) { return true; } return null;', 0],
            ['if ($value) { return true; } return fallback();', 0],
            ['if ($value) { return true; } work(); return false;', 0],
            ['if ($value) { work(); return true; } return false;', 0],
            ['if ($value) { return true; } else { work(); return false; }', 0],
            ['if ($value) { return true; } elseif (other()) { return false; } else { return false; }', 0],
            ['if ($value) { return true; }', 0],
            ['if ($outer) { if ($inner) { return true; } } return false;', 0],
            ['try { if ($value) { return true; } } finally { return false; }', 0],
            ['if ($value) { yield true; } else { yield false; }', 0],
            ['$later = function () { if ($value) { return true; } }; return false;', 0],
        ];
    }

    public function testDiagnosticsPreserveBooleanConversion(): void
    {
        $rule = new NoRedundantBooleanReturns;
        $findings = FiveRuleFixture::findings($rule, "<?php\nif (ready()) { return true; }\nreturn false;");
        self::assertCount(1, $findings);
        self::assertSame('no_redundant_boolean_returns', $findings[0]->rule);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('return (bool) (condition)', $findings[0]->message);
        self::assertStringContainsString('once', $findings[0]->message);
        $inverse = FiveRuleFixture::findings($rule, '<?php if ($value) { return false; } else { return true; }');
        self::assertStringContainsString('return ! (condition)', $inverse[0]->message);
        self::assertSame([], $rule->requiredAnalyses());
        self::assertSame(NoRedundantBooleanReturns::class, RuleCatalog::standardDefinitions()[$rule->id()]['class']);
    }

    #[DataProvider('values')]
    public function testReplacementKeepsResultAndEvaluationCount(mixed $value): void
    {
        $calls = 0;
        $condition = static function () use ($value, &$calls) {
            $calls++;

            return $value;
        };
        $original = static function () use ($condition) {
            if ($condition()) {
                return true;
            }

            return false;
        };
        $expected = $original();
        self::assertSame(1, $calls);
        $calls = 0;
        self::assertSame($expected, (bool) $condition());
        self::assertSame(1, $calls);
        $calls = 0;
        self::assertSame(! $expected, ! $condition());
        self::assertSame(1, $calls);
    }

    public static function values(): array
    {
        return array_map(static fn ($value): array => [$value], [null, false, true, 0, 1, '', '0', 'false', [], [1], new \stdClass]);
    }
}
