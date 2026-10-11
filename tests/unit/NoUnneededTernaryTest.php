<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Rules\Complexity\NoUnneededTernary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoUnneededTernaryTest extends TestCase
{
    #[DataProvider('expressions')]
    public function testLiteralBoundaries(string $expression, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoUnneededTernary, '<?php $result = '.$expression.';'));
    }

    public static function expressions(): array
    {
        return [
            ['isReady() ? true : false', 1],
            ['$unknown ? false : true', 1],
            ['$unknown ? TRUE : FALSE', 1],
            ['($a === $b) ? (true) : (false)', 1],
            ['$unknown ? true : true', 0],
            ['$unknown ? false : false', 0],
            ['$unknown ? 1 : 0', 0],
            ['$unknown ? "true" : "false"', 0],
            ['$unknown ? null : false', 0],
            ['$unknown ? false : null', 0],
            ['$unknown ? true : MAYBE', 0],
            ['$unknown ? MAYBE : false', 0],
            ['$unknown ? true : Other\false', 0],
            ['$unknown ?: false', 0],
            ['$unknown ?? false', 0],
            ['$unknown ? $unknown : fallback()', 0],
            ['$a ? ($b ? true : false) : false', 1],
            ['fn () => $a ? true : false', 1],
            ['new class { function run() { return $a ? true : false; } }', 1],
        ];
    }

    public function testDiagnosticPreservesUnknownConditionType(): void
    {
        $finding = FiveRuleFixture::findings(new NoUnneededTernary, "<?php\n\$result = \$unknown ? true : false;")[0];
        self::assertSame('no_unneeded_ternary', $finding->rule);
        self::assertSame(2, $finding->line);
        self::assertSame(1, $finding->value);
        self::assertSame(0, $finding->threshold);
        self::assertStringStartsWith('Unneeded boolean ternary. Use (bool) (condition);', $finding->message);
        self::assertStringContainsString('once', $finding->message);
        $negative = FiveRuleFixture::findings(new NoUnneededTernary, '<?php $result = $unknown ? false : true;')[0];
        self::assertStringContainsString('! (condition)', $negative->message);
    }

    #[DataProvider('values')]
    public function testSuggestedConversionsPreservePhpRuntimeSemantics(mixed $value): void
    {
        $calls = 0;
        $condition = static function () use ($value, &$calls) {
            $calls++;

            return $value;
        };
        $positive = $condition() ? true : false;
        self::assertSame(1, $calls);
        $calls = 0;
        self::assertSame($positive, (bool) $condition());
        self::assertSame(1, $calls);
        $calls = 0;
        $negative = $condition() ? false : true;
        self::assertSame(1, $calls);
        $calls = 0;
        self::assertSame($negative, ! $condition());
        self::assertSame(1, $calls);
    }

    public static function values(): array
    {
        return [[null], [false], [true], [0], [1], [-1], [0.0], [NAN], [INF], [''], ['0'], ['false'], ['00'], [[]], [[0]], [new \stdClass]];
    }
}
