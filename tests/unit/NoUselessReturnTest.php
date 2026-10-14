<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Complexity\NoUselessReturn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoUselessReturnTest extends TestCase
{
    #[DataProvider('sources')]
    public function testCallableBoundaries(string $source, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoUselessReturn, '<?php '.$source));
    }

    public static function sources(): array
    {
        return [
            ['function run() { return; }', 1],
            ['function run(): void { work(); return; }', 1],
            ['class Task { public function run() { return; } }', 1],
            ['$run = function () { return; };', 1],
            ['$task = new class { public function run() { return; } };', 1],
            ['function run() { $later = function () { return; }; return; }', 2],
            ['function run() { function nested() { return; } work(); }', 1],
            ['function run() { yield 1; return; }', 1],
            ['function run() { try { work(); } finally { cleanup(); } return; }', 1],
            ['function run() { if (ready()) { return; } work(); }', 0],
            ['function run() { if (ready()) { return; } }', 0],
            ['function run() { try { work(); } finally { return; } }', 0],
            ['function run() { return null; }', 0],
            ['function run() { return work(); }', 0],
            ['function run() {}', 0],
            ['interface Task { public function run(); }', 0],
            ['$run = fn () => null;', 0],
            ['return;', 0],
            ['function &run() { return; }', 0],
        ];
    }

    public function testDiagnosticAndCatalog(): void
    {
        $rule = new NoUselessReturn;
        $findings = FiveRuleFixture::findings($rule, "<?php\nfunction run(): void {\n    work();\n    return;\n}");
        self::assertCount(1, $findings);
        self::assertSame('no_useless_return', $findings[0]->rule);
        self::assertSame(4, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('Remove', $findings[0]->message);
        self::assertSame([], $rule->requiredAnalyses());
        self::assertSame(NoUselessReturn::class, RuleCatalog::standardDefinitions()[$rule->id()]['class']);
    }
}
