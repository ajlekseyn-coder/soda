<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoCommentedOutCodeTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('explanations')]
    public function testStandardRunnerKeepsExplanationsAndReportsDisabledCode(string $explanation): void
    {
        $findings = $this->findings('// '.$explanation."\n// \$result = run();");

        self::assertCount(1, $findings);
        self::assertSame(3, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame(0, $findings[0]->threshold);
        self::assertNull($findings[0]->class);
        self::assertNull($findings[0]->method);
        self::assertSame('Commented-out code: $result = run();', $findings[0]->message);
    }

    public static function explanations(): iterable
    {
        yield 'interface prose' => ['We will mark this supervisor as terminating so that any user interface can'];
        yield 'function prose' => ['so we will execute the value function which will resolve it if needed.'];
        yield 'leading function prose' => ['function which we were given. Then, we will sort the returned values and'];
        yield 'enum prose' => ['An empty enum matches no value at all and is rejected outright under strict schemas...'];
        yield 'short function prose' => ['Clean up function arguments'];
        yield 'class prose' => ['Ignore any reflection or class loading errors'];
        yield 'inline call explanation' => ['call $plan->setKey() if setKey method exists'];
        yield 'leading static illustration' => ['Resource ::collection() / ::make() entity types fully describe the return shape;'];
        yield 'control flow illustration' => ['the true is false when if ($this->isInvalid($request)) { throw new Exception(); }'];
        yield 'array illustration' => ["Could be ['author'] (indexed) or ['author' => UserResource::class] (keyed)"];
        yield 'inline example' => ['Use `new Worker()` to start processing.'];
        yield 'backtick declaration prose' => ['The class `Worker` provides an example.'];
        yield 'return explanation' => ['return early if cache is warm'];
    }

    #[DataProvider('disabledLines')]
    public function testDisabledPhpStatementsDeclarationsAndFragmentsRemainReported(string $line): void
    {
        $findings = $this->findings('// '.$line);

        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(1, $findings[0]->value);
        self::assertSame('Commented-out code: '.$line, $findings[0]->message);
    }

    public static function disabledLines(): iterable
    {
        yield 'variable statement' => ['$result = run();'];
        yield 'shell execution assignment' => ['$result = `whoami`;'];
        yield 'trailing explanation' => ['$result = run(); // see `run()`'];
        yield 'method call' => ['$object->run();'];
        yield 'static call' => ['Worker::run();'];
        yield 'construction' => ['new Worker();'];
        yield 'interface declaration' => ['interface Channel {}'];
        yield 'class header' => ['class Channel'];
        yield 'trait header' => ['trait Channel'];
        yield 'unfinished class with interpolation' => ['class Channel { function run() { echo "{$value}";'];
        yield 'function declaration' => ['function calculate() { return 10; }'];
        yield 'method header' => ['protected function render(): void'];
        yield 'abstract method' => ['abstract public function render(): string|false;'];
        yield 'control header' => ['if ($user->isActive()) {'];
        yield 'alternative branch header' => ['elseif ($user->isActive()) {'];
        yield 'case header' => ['case $command->kind:'];
        yield 'partial call' => ['$app->instance('];
        yield 'multiline return' => ['return Arr::first('];
        yield 'array value fragment' => ['$this->children,'];
        yield 'array key fragment' => ["'locale' => \$this->locale,"];
        yield 'arrow fragment' => ['fn($child) => $child->name() === $name,'];
        yield 'chained call fragment' => ["->setExceptionHandler(\$app['exception'])"];
        yield 'coalesce fragment' => ['?? throw new InvalidArgumentException('];
    }

    public function testBlockCommentLinesKeepLocationsAndDocblocksRemainDocumentation(): void
    {
        $findings = $this->findings(<<<'PHP'
/**
 * new Worker();
 */
/*
 * We will resolve the class name before invoking it.
 * $result = run();
 * $object->run();
 */
$text = '// new Worker();';
PHP);

        self::assertCount(2, $findings);
        self::assertSame([7, 8], array_column($findings, 'line'));
        self::assertSame([1, 2], array_column($findings, 'value'));
    }

    private function findings(string $body): array
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-commented-code-');
        self::assertIsString($path);
        file_put_contents($path, "<?php\n".$body."\n");

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            self::assertCount(0, $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error'));

            return $result->violations->filter(static fn ($violation): bool => $violation->rule === 'max_commented_out_code_lines')->values()->all();
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('pipelineRegressions')]
    public function testPipelinePreservesValidCodeAndDetectsControl(string $valid, string $invalid): void
    {
        $this->assertRulePipeline('max_commented_out_code_lines', $valid, $invalid);
    }

    public static function pipelineRegressions(): iterable
    {
        yield 'Serializable Closure: inline example is documentation' => [
            'function reflect() {
// Created from a method (e.g. `$obj->method(...)`), attributes are inherited.
}',
            'function reflect() {
// $obj->method();
}',
        ];
    }
}
