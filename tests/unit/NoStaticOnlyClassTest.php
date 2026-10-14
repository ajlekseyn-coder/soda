<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\SodaInitFileEmitter;
use Cosmira\Soda\Rules\Structure\NoStaticOnlyClass;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoStaticOnlyClassTest extends TestCase
{
    #[DataProvider('classes')]
    public function testStaticOnlySurfaces(string $source, int $count): void
    {
        self::assertCount($count, $this->findings($source));
    }

    public static function classes(): iterable
    {
        yield 'static methods only' => ['final class Rates { public static function usd(): int { return 1; } private static function eur(): int { return 2; } }', 1];
        yield 'static properties and methods' => ['final class Rates { public static int $base = 1; public static function usd(): int { return self::$base; } }', 1];
        yield 'constants alone are accepted' => ['final class Rates { public const USD = 1; private const EUR = 2; }', 0];
        yield 'constants with static methods' => ['final class Rates { public const USD = 1; public static function usd(): int { return self::USD; } }', 1];
        yield 'instance method keeps the class' => ['final class Rates { public int $base = 1; public static function usd(): int { return 1; } public function total(): int { return $this->base; } }', 0];
        yield 'instance property keeps the class' => ['final class Rates { public int $base = 1; public static function usd(): int { return 1; } }', 0];
        yield 'promoted state keeps the class' => ['final class Rates { public function __construct(private readonly int $base) {} public static function usd(): int { return 1; } }', 0];
        yield 'no methods is a constant holder' => ['final class Rates { public const USD = 1; }', 0];
        yield 'abstract classes are out of scope' => ['abstract class Rates { abstract public function total(): int; public static function usd(): int { return 1; } }', 0];
        yield 'anonymous classes are out of scope' => ['function make(): object { return new class { public static function usd(): int { return 1; } }; }', 0];
        yield 'inherited surface is unknown' => ['final class Rates extends BaseRates { public static function usd(): int { return 1; } }', 0];
        yield 'trait composition is unknown' => ['final class Rates { use MakesRates; public static function usd(): int { return 1; } }', 0];
        yield 'interfaces and enums are out of scope' => ['interface Rates { public function total(): int; } enum Channel: string { case Mail = "mail"; }', 0];
    }

    public function testReportsTheClassNameAndLineWithAdvice(): void
    {
        $findings = $this->findings(<<<'PHP'
<?php

final class InvoiceRates
{
    public static function usd(): int
    {
        return 1;
    }

    private static function eur(): int
    {
        return 2;
    }
}
PHP);
        self::assertCount(1, $findings);
        self::assertSame('no_static_only_class', $findings[0]->rule);
        self::assertSame('InvoiceRates', $findings[0]->class);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('InvoiceRates declares only static members', $findings[0]->message);
        self::assertStringContainsString('functions or constants', $findings[0]->message);
    }

    public function testRuleStaysOutOfTheStandardBundle(): void
    {
        self::assertFalse(RuleCatalog::definitions()['no_static_only_class']['standard']);
        self::assertNotContains(
            'no_static_only_class',
            array_map(static fn ($rule): string => $rule->id(), RuleCatalog::standard()),
        );
        self::assertStringNotContainsString('NoStaticOnlyClass', SodaInitFileEmitter::emit());
    }

    private function findings(string $source): array
    {
        $source = str_starts_with($source, '<?php') ? $source : '<?php '.$source;
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        return iterator_to_array((new NoStaticOnlyClass)->checkFile(new FileFacts('/static-only.php', $source, $nodes, [])));
    }
}
