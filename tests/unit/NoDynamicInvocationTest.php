<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Architecture\NoDynamicInvocation;
use Cosmira\Soda\Tests\ParsesPhpSnippets;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoDynamicInvocationTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('calls')]
    public function testExplicitTargetsAndComputedTargets(string $source, int $expected): void
    {
        $code = '<?php '.$source;
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($code);
        $nodes = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($nodes);
        $findings = iterator_to_array((new NoDynamicInvocation)->checkFile(new FileFacts('/project/calls.php', $code, $nodes, [])));
        self::assertCount($expected, $findings);
    }

    public static function calls(): iterable
    {
        yield 'literal closure' => ['(function () { return 1; })();', 0];
        yield 'literal arrow' => ['(fn () => 1)();', 0];
        yield 'static literal closure' => ['(static function () { return 1; })();', 0];
        yield 'static literal arrow' => ['(static fn () => 1)();', 0];
        yield 'computed selection' => ['($flag ? fn () => 1 : fn () => 2)();', 0];
        yield 'nested computed call' => ['(function () use ($callback) { return $callback(); })();', 0];
        yield 'declared closure contract' => ['function run(Closure $callback) { $callback(); }', 0];
        yield 'literal method string' => ['$object->{"known"}();', 0];
        yield 'nullsafe literal method string' => ['$object?->{"known"}();', 0];
        yield 'static literal method string' => ['Receiver::{"known"}();', 0];
        yield 'lexical function name' => ['function original($object) { return $object->{__FUNCTION__}(); }', 0];
        yield 'lexical method name' => ['class Receiver { function original($object) { return $object->{__METHOD__}(); } }', 0];
        yield 'class name' => ['class Receiver { function original($object) { return $object->{__CLASS__}(); } }', 0];
        yield 'trait name' => ['trait Original { function original($object) { return $object->{__TRAIT__}(); } }', 0];
        yield 'namespace name' => ['namespace App; $object->{__NAMESPACE__}();', 0];
        yield 'source file name' => ['$object->{__FILE__}();', 0];
        yield 'source directory name' => ['$object->{__DIR__}();', 0];
        yield 'property hook name' => ['class Receiver { public string $label { get => $this->{__PROPERTY__}(); } }', 0];
        yield 'trait alias and inherited function identity' => ['trait Original { function original($object) { return $object->{__FUNCTION__}(); } } class Receiver { use Original { original as alias; } } class Child extends Receiver {}', 0];
        yield 'closure function identity' => ['$callback = function ($object) { return $object->{__FUNCTION__}(); };', 0];
        yield 'nullsafe magic name' => ['function original($object) { return $object?->{__FUNCTION__}(); }', 0];
        yield 'static magic name' => ['function original() { return Receiver::{__FUNCTION__}(); }', 0];
        yield 'magic method reference' => ['function original($object) { return $object->{__FUNCTION__}(...); }', 0];
        yield 'literal method reference' => ['$object->{"known"}(...);', 0];
        yield 'runtime method reference' => ['$object->{$method}(...);', 1];
        yield 'integer magic value is not a string name' => ['$object->{__LINE__}();', 1];
        yield 'integer literal is not a string name' => ['$object->{123}();', 1];
        yield 'ordinary class constant' => ['$object->{Unknown::METHOD}();', 1];
        yield 'dynamic class constant' => ['$object->{$class::METHOD}();', 1];
        yield 'effectful class name' => ['$object->{factory()::class}();', 1];
        yield 'interpolated method name' => ['$object->{"prefix{$name}"}();', 1];
        yield 'concatenated method name' => ['$object->{__FUNCTION__.$suffix}();', 1];
        yield 'bounded method selection' => ['$object->{$flag ? "first" : "second"}();', 1];
        yield 'static computed method' => ['Receiver::{$method}();', 1];
        yield 'nullsafe dynamic method' => ['$object?->$method();', 1];
        yield 'dynamic method' => ['$object->$method();', 1];
        yield 'typed model construction' => ['/** @param class-string<Model> $model */ function build(string $model): Model { return new $model(); }', 0];
        yield 'dynamic class' => ['new $class();', 0];
        yield 'indirect dispatcher' => ['call_user_func($callback);', 1];
    }

    public function testStandardRunnerKeepsLexicalNamesAndReportsRuntimeSelection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-static-method-');
        self::assertIsString($path);
        file_put_contents($path, <<<'PHP'
<?php
trait OriginalResponse
{
    private mixed $original = null;
    public function getOriginalContent(): mixed
    {
        $original = $this->original;
        return $original instanceof self ? $original->{__FUNCTION__}() : $original;
    }
}
class Response { use OriginalResponse; }
function genuine(object $receiver, string $method): mixed
{
    return $receiver->{$method}();
}
PHP);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_dynamic_invocation')->values();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
            self::assertSame(14, $findings[0]->line);
            self::assertSame(1, $findings[0]->value);
            self::assertSame(0, $findings[0]->threshold);
            self::assertSame('Computed method names are forbidden by this policy. Use a literal method name or explicit dispatch.', $findings[0]->message);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('pipelineRegressions')]
    public function testPipelinePreservesValidCodeAndDetectsControl(string $valid, string $invalid): void
    {
        $this->assertRulePipeline('no_dynamic_invocation', $valid, $invalid);
    }

    public static function pipelineRegressions(): iterable
    {
        yield 'Prompts: immediately invoked closure has an explicit target' => [
            'function resetState() { return (function () { clearState(); return 1; })(); }',
            'function resetState($callback) { return call_user_func($callback); }',
        ];
    }
}
