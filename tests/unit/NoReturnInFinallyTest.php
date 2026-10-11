<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Rules\Complexity\NoReturnInFinally;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoReturnInFinallyTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testLexicalBoundaries(string $body, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoReturnInFinally, '<?php function run() { '.$body.' }'));
    }

    public static function bodies(): array
    {
        return [
            ['try { work(); } finally { return; }', 1],
            ['try { return 1; } finally { return 2; }', 1],
            ['try { work(); } finally { if ($ready) { return false; } }', 1],
            ['try { work(); } finally { foreach ($items as $item) { return $item; } }', 1],
            ['try { work(); } finally { try { return 1; } catch (Exception $e) { return 2; } }', 2],
            ['try { work(); } finally { try { work(); } finally { return 1; } }', 1],
            ['try { return 1; } finally { cleanup(); }', 0],
            ['try { work(); } catch (Exception $e) { return false; } finally { cleanup(); }', 0],
            ['try { work(); } finally { throw new Exception; }', 0],
            ['try { work(); } finally { $later = function () { return 1; }; }', 0],
            ['try { work(); } finally { $later = fn () => 1; }', 0],
            ['try { work(); } finally { function nested() { return 1; } }', 0],
            ['try { work(); } finally { $x = new class { function run() { return 1; } }; }', 0],
            ['try { work(); } finally { $later = function () { try { work(); } finally { return 1; } }; }', 1],
            ['try { work(); } finally { while ($ready) { break; } }', 0],
            ['return 1;', 0],
            ['yield 1; try { work(); } finally { return 2; }', 1],
        ];
    }

    public function testFileScopeAndReturnLine(): void
    {
        $findings = FiveRuleFixture::findings(new NoReturnInFinally, "<?php\ntry { work(); }\nfinally {\n    return 2;\n}");
        self::assertCount(1, $findings);
        self::assertSame('no_return_in_finally', $findings[0]->rule);
        self::assertSame(4, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('suppress an exception', $findings[0]->message);
        self::assertSame([], FiveRuleFixture::findings(new NoReturnInFinally, '<?php return 2;'));
    }

    public function testPhpFinallyActuallyReplacesPendingResultsAndExceptions(): void
    {
        $replace = static function () {
            try {
                return 'original';
            } finally {
                return 'replacement';
            }
        };
        $suppress = static function () {
            try {
                throw new \RuntimeException('original');
            } finally {
                return 'replacement';
            }
        };
        self::assertSame('replacement', $replace());
        self::assertSame('replacement', $suppress());
    }
}
