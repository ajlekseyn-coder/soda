<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoUselessConcat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoUselessConcatTest extends TestCase
{
    #[DataProvider('expressions')]
    public function testLiteralBoundaries(string $expression, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoUselessConcat, '<?php $value = '.$expression.';'));
    }

    public static function expressions(): array
    {
        return [
            ["'hello' . ' world'", 1],
            ["'hello' . \" world\"", 1],
            ["'' . ''", 1],
            ["'a' . 'b' . 'c'", 1],
            ["('a' . 'b') . ('c' . 'd')", 2],
            ["'a' .\n'b'", 0],
            ["'a' . \$value", 0],
            ["\$value . ''", 0],
            ["'a' . value()", 0],
            ["'a' . 1", 0],
            ["\"hello \$name\" . ' world'", 0],
            ["'a' . SUFFIX", 0],
            ["'a'", 0],
        ];
    }

    public function testDiagnosticAndCatalog(): void
    {
        $rule = new NoUselessConcat;
        $findings = FiveRuleFixture::findings($rule, "<?php\nfunction run() {\n    return 'hello' . ' world';\n}");
        self::assertCount(1, $findings);
        self::assertSame('no_useless_concat', $findings[0]->rule);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('literal', $findings[0]->message);
        self::assertSame([], $rule->requiredAnalyses());
        self::assertSame(NoUselessConcat::class, RuleCatalog::standardDefinitions()[$rule->id()]['class']);
    }
}
