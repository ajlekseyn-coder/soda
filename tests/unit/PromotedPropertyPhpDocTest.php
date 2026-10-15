<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Documentation\MultilinePropertyPhpDoc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromotedPropertyPhpDocTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('declarations')]
    public function testPromotedProperties(string $code, array $visibilities, array $names): void
    {
        $file = new FileFacts('fixture.php', '<?php '.$code, $this->parseSnippet($code), []);
        $hits = iterator_to_array((new MultilinePropertyPhpDoc($visibilities))->checkFile($file));
        self::assertCount(count($names), $hits);
        foreach ($hits as $index => $hit) {
            self::assertSame('multiline_property_phpdoc', $hit->rule);
            self::assertNull($hit->method);
            self::assertGreaterThan(0, $hit->line);
            self::assertStringContainsString('property::'.$names[$index].' ', $hit->message);
        }
    }

    #[DataProvider('parameterTypes')]
    public function testConstructorParameterTypeKeepsItsExactTarget(string $annotation, string $parameters, array $names): void
    {
        $code = "class Example {\n/**\n * Preserve the source options.\n *\n * @param ".$annotation."\n */\npublic function __construct(".$parameters.") {}\n}";
        $this->testPromotedProperties($code, ['public', 'protected', 'private'], $names);
    }

    public static function parameterTypes(): iterable
    {
        yield 'multiline shape' => ["array{\n * stop: bool,\n *} \$config", 'public array $config', []];
        yield 'multiline nested generics' => ["array<string,\n * array<int, string>> \$config", 'public array $config', []];
        yield 'callable parameter is not the promoted field' => ['Closure(string $label): void $callback', 'public \Closure $callback, public string $label', ['label']];
        yield 'multiline callable' => ["Closure(\n * string \$label,\n * int \$flags,\n *): void \$callback", 'public \Closure $callback', []];
        yield 'nested callable in generics' => ['array<string, array<int, Closure(string $label): void>> $config', 'public array $config', []];
        yield 'quoted dollar key is not the promoted field' => ['array{"$config": int} $other', 'public array $config', ['config']];
        yield 'quoted dollar key does not hide the actual target' => ['array{"$other": int} $config', 'public array $config', []];
        yield 'parameter prefix is not an exact match' => ['array $configuration', 'public array $config', ['config']];
        yield 'description mention is not the target' => ['string $other Do not use $config here.', 'public string $config', ['config']];
        yield 'type is required' => ['$config', 'public array $config', ['config']];
        yield 'reference without a type is not documentation' => ['&$config', 'public array $config', ['config']];
        yield 'reference annotation' => ['array &$config', 'public array &$config', []];
        yield 'intersection type' => ['(\Countable&\IteratorAggregate)|array $config', 'public mixed $config', []];
        yield 'literal integer type' => ['0|1 $config', 'public int $config', []];
        yield 'later parameter tag remains separate' => ["array{\n * stop: bool,\n *} \$config\n * @param int \$count", 'public array $config, public int $count, public int $missing', ['missing']];
    }

    public function testStandardRunnerAcceptsAMultilineConstructorTagOnlyForItsOwnProperty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-promoted-shape-');
        self::assertIsString($path);
        file_put_contents($path, <<<'PHP'
<?php
class Options {
    /**
     * Keep the reporting options.
     *
     * @param array{
     *     stop: bool,
     * } $config
     */
    public function __construct(
        private array $config,
        private string $missing,
    ) {}
}
PHP);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'multiline_property_phpdoc')->values();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
            self::assertSame('Options', $findings[0]->class);
            self::assertSame(12, $findings[0]->line);
            self::assertSame(0, $findings[0]->value);
            self::assertSame(1, $findings[0]->threshold);
            self::assertStringContainsString('property::missing ', $findings[0]->message);
        } finally {
            unlink($path);
        }
    }

    public static function declarations(): iterable
    {
        $all = ['public', 'protected', 'private'];
        yield 'all visibility' => ['class Example { function __construct(public int $one, protected string $two, private readonly bool $three) {} }', $all, ['one', 'two', 'three']];
        yield 'default public' => ['class Example { function __construct(public int $one, protected string $two, private bool $three) {} }', ['public'], ['one']];
        yield 'ordinary parameter' => ['class Example { function __construct(int $one) {} }', $all, []];
        yield 'own block' => ["class Example { function __construct(\n/**\n * The retry limit.\n */\npublic int \$limit) {} }", $all, []];
        yield 'one line' => ['class Example { function __construct(/** Retry limit. */ public int $limit) {} }', $all, ['limit']];
        yield 'constructor tag' => ["class Example {\n/**\n * @param int \$limit The retry limit.\n */\nfunction __construct(public int \$limit) {} }", $all, ['limit']];
        yield 'generic type' => ["class Example {\n/**\n * @param array<string, int> \$limits\n */\nfunction __construct(public array \$limits) {} }", $all, ['limits']];
        yield 'wrong tag' => ["class Example {\n/**\n * Configure retry limits.\n *\n * @param int \$limits\n */\nfunction __construct(public int \$limit) {} }", $all, ['limit']];
        yield 'constructor summary alone' => ["class Example {\n/**\n * Configure retries.\n */\nfunction __construct(public int \$limit) {} }", $all, ['limit']];
        yield 'unrelated mention' => ["class Example {\n/**\n * Use \$limit for retries.\n */\nfunction __construct(public int \$limit) {} }", $all, ['limit']];
        yield 'only documented parameter' => ["class Example {\n/**\n * Configure retry limits and delays.\n *\n * @param int \$limit\n */\nfunction __construct(public int \$limit, public int \$delay) {} }", $all, ['delay']];
        yield 'constructor summary and tag' => ["class Example {\n/**\n * Configure retry limits.\n *\n * @param int \$limit The retry limit.\n */\nfunction __construct(public int \$limit) {} }", $all, []];
        yield 'constructor summary and generic tag' => ["class Example {\n/**\n * Configure retry limits by endpoint.\n *\n * @param array<string, int> \$limits\n */\nfunction __construct(public array \$limits) {} }", $all, []];
        yield 'own tag only' => ["class Example { function __construct(\n/**\n * @var int\n */\npublic int \$limit) {} }", $all, ['limit']];
        yield 'promoted trait' => ['trait Example { function __construct(public int $limit) {} }', $all, ['limit']];
        yield 'anonymous class' => ['$object = new class(1) { function __construct(public int $limit) {} };', $all, ['limit']];
        yield 'inherited not duplicated' => ['class Base { function __construct(public int $limit) {} } class Child extends Base {}', $all, ['limit']];
        yield 'readonly class' => ['readonly class Example { function __construct(public int $limit) {} }', $all, ['limit']];
        yield 'attributes on property' => ['class Example { function __construct(#[Example] public int $limit) {} }', $all, ['limit']];
        yield 'constructor one line tag' => ['class Example { /** @param int $limit */ function __construct(public int $limit) {} }', $all, ['limit']];
    }
}
