<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Usage\NoUnusedParameters;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoUnusedParametersTest extends TestCase
{
    #[DataProvider('cases')]
    public function testLexicalUsage(string $body, int $expected): void
    {
        $source = '<?php function operation($input) { '.$body.' }';
        $findings = $this->findings($source);
        self::assertCount($expected, $findings);
        if ($expected !== 0) {
            self::assertSame(1, $findings[0]->line);
            self::assertSame(1, $findings[0]->value);
            self::assertSame(0, $findings[0]->threshold);
            self::assertStringContainsString('$input', $findings[0]->message);
        }
    }

    public static function cases(): iterable
    {
        yield 'throwing match default does not end a selected arm' => ['$mode = match (kind()) { 1 => "one", default => throw new Exception }; return $input;', 0];
        yield 'throwing match arm does not hide the default read' => ['return match (kind()) { 1 => throw new Exception, default => $input };', 0];
        yield 'match arms cannot overwrite each other' => ['return match (kind()) { 1 => ($input = 1), default => $input };', 0];
        yield 'every surviving match arm overwrites input' => ['match (kind()) { 1 => ($input = 1), default => ($input = 2) }; return $input;', 1];
        yield 'throwing arm does not preserve an overwritten input' => ['match (kind()) { 1 => ($input = 1), default => throw new Exception }; return $input;', 1];
        yield 'unmatched match values throw without hiding a continuing arm' => ['match (kind()) { 1 => throw new Exception, 2 => false }; return $input;', 0];
        yield 'default written first is still an alternative' => ['match (kind()) { default => ($input = 1), 1 => "one" }; return $input;', 0];
        yield 'one match arm condition can skip another condition' => ['return match (kind()) { firstCase(), ($input = 1) => $input, default => null };', 0];
        yield 'match condition writes happen before selecting default' => ['match (kind()) { default => "other", ($input = 1) => "one" }; return $input;', 1];
        yield 'empty match still reads its subject' => ['return match ($input) {};', 0];
        yield 'forwarded argument list reads inputs' => ['return forward(func_get_args());', 0];
        yield 'forwarded argument after overwrite' => ['$input = 1; return func_get_args();', 1];
        yield 'argument list in nested closure' => ['return function () { return func_get_args(); };', 1];
        yield 'selected incoming argument' => ['return func_get_arg(0);', 0];
        yield 'another argument position' => ['return func_get_arg(1);', 1];
        yield 'input used by exception recovery' => ['try { return attempt(); } catch (Exception $error) { return $input; }', 0];
        yield 'input used by finally after return' => ['try { return attempt(); } finally { consume($input); }', 0];
        yield 'finally can observe input when a call throws before overwrite' => ['try { attempt(); $input = 1; } finally { consume($input); }', 0];
        yield 'input used by another switch case' => ['switch (status()) { case 1: return null; default: return $input; }', 0];
        yield 'every switch case overwrites input' => ['switch (status()) { case 1: $input = 1; break; default: $input = 2; } return $input;', 1];
        yield 'included file inherits parameters' => ['require "bootstrap.php";', 0];
        yield 'included file after overwrite' => ['$input = 1; require "bootstrap.php";', 1];
        yield 'compact reads the named input' => ['return compact("input");', 0];
        yield 'compact does not read another input' => ['return compact("other");', 1];
        yield 'compact after overwrite' => ['$input = 1; return compact("input");', 1];
        yield 'compact in closure has a separate scope' => ['return function () { return compact("input"); };', 1];
        yield 'qualified compact reads the input' => ['return \\compact("input");', 0];
        yield 'unrelated qualified compact' => ['return Other\\compact("input");', 1];
        yield 'compact callable is not invoked' => ['return compact(...);', 1];
        yield 'compact accepts nested name arrays' => ['return compact(["other", ["input"]]);', 0];
        yield 'compact array keys are not variable names' => ['return compact(["input" => "other"]);', 1];
        yield 'computed compact name may select input' => ['return compact(variableName());', 0];
        yield 'local variable snapshot' => ['return get_defined_vars();', 0];
        yield 'qualified snapshot' => ['return \\get_defined_vars();', 0];
        yield 'snapshot after overwrite' => ['$input = 1; return get_defined_vars();', 1];
        yield 'snapshot callable is not invoked' => ['return get_defined_vars(...);', 1];
        yield 'snapshot in arrow has its own scope' => ['return fn () => get_defined_vars();', 1];
        yield 'snapshot in closure has its own scope' => ['return function () { return get_defined_vars(); };', 1];
        yield 'unrelated qualified function' => ['return Other\\get_defined_vars();', 1];
        yield 'arrow return does not exit outer function' => ['$later = fn () => 1; return $input;', 0];
        yield 'arrow write does not overwrite outer input' => ['$later = fn () => $input = 1; return $input;', 0];
        yield 'unused'  => ['return 1;', 1];
        yield 'used' => ['return $input;', 0];
        yield 'passed' => ['consume($input);', 0];
        yield 'captured' => ['$later = function () use ($input) { return $input; };', 0];
        yield 'arrow capture' => ['$later = fn () => $input;', 0];
        yield 'shadowed arrow parameter' => ['$later = fn ($input) => $input;', 1];
        yield 'shadowed closure parameter' => ['$later = function ($input) { return $input; };', 1];
        yield 'nested named function' => ['function inner($input) { return $input; }', 1];
        yield 'anonymous object method' => ['$object = new class { function inner($input) { return $input; } };', 1];
    }

    public function testIncomingValueMustSurviveUntilARead(): void
    {
        foreach ([
            '$input = 1; return $input;',
            '$input;',
            'ready() ? ($input = 1) : ($input = 2); return $input;',
            'if (ready()) { $input = 1; } else { $input = 2; } return $input;',
            'if (ready()) { return 1; } $input = 2; return $input;',
        ] as $body) {
            self::assertCount(1, $this->findings('<?php function operation($input) { '.$body.' }'), $body);
        }
        foreach ([
            '$copy = $input; $input = 1; return $copy;',
            'if (ready()) { $input = 1; } return $input;',
            '$input = transform($input); return $input;',
            'ready() && ($input = 1); return $input;',
            'ready() || ($input = 1); return $input;',
            'ready() and ($input = 1); return $input;',
            'ready() or ($input = 1); return $input;',
            'ready() ?? ($input = 1); return $input;',
            'ready() ? ($input = 1) : null; return $input;',
            'ready() ? null : ($input = 1); return $input;',
        ] as $body) {
            self::assertSame([], $this->findings('<?php function operation($input) { '.$body.' }'), $body);
        }
        self::assertSame([], $this->findings('<?php function fill(&$output) { $output = 1; }'));
    }

    public function testPromotionAndAbstractContractsHaveNoUnusedInput(): void
    {
        self::assertSame([], $this->findings('<?php interface Contract { function run($input); } class Record { function __construct(public string $input) {} }'));
    }

    public function testPhpMagicSignaturesPreserveOnlyRequiredPositions(): void
    {
        $source = '<?php class Attributes { public function __call($method, $arguments) {} public static function __callStatic($method, $arguments) {} public function __get($name) {} public function __set($name, $value) {} public function __isset($name) {} public function __unset($name) {} public function __unserialize(array $data): void {} public static function __set_state(array $properties) {} }';
        self::assertSame([], $this->findings($source));
        self::assertSame([], $this->findings('<?php $pool = new class { public function __call($method, $arguments) {} };'));
        self::assertCount(2, $this->findings('<?php class Pool { public function call($method, $arguments) {} }'));
        self::assertCount(2, $this->findings('<?php function __call($method, $arguments) {}'));
        self::assertCount(1, $this->findings('<?php class Pool { public function __invoke($input) {} }'));
        self::assertCount(1, $this->findings('<?php class Pool { public function __construct($input) {} }'));
        self::assertCount(1, $this->findings('<?php class Pool { public function __get($name, $extra = null) {} }'));
    }

    public function testStandardRunnerPreservesImplicitReadsAndAlternativePaths(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-input-contracts-');
        self::assertIsString($path);
        file_put_contents($path, <<<'PHP'
<?php
class NullPool { public function __call($method, $arguments) {} }
function forward($first, $second) { return dispatch(func_get_args()); }
function attributes($name) { return compact('name'); }
function recover($failure) { try { return attempt(); } catch (Exception $error) { return $failure(); } }
function selected($input) { switch (status()) { case 1: return null; default: return $input; } }
function unused($input) { return 1; }
PHP);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
            self::assertCount(1, $findings);
            self::assertStringContainsString('unused', $findings[0]->message);
        } finally {
            unlink($path);
        }
    }

    public function testStandardRunnerKeepsReadsAfterAThrowingMatchArm(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-match-inputs-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function connect($host, $protocol) { $router = match ($protocol) { "pusher" => router(), default => throw new Exception }; return openSocket($host, $router); } function unused($host, $protocol) { return match ($protocol) { "pusher" => router(), default => throw new Exception }; }');

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
            self::assertCount(1, $findings);
            self::assertStringContainsString('unused', $findings[0]->message);
            self::assertStringContainsString('$host', $findings[0]->message);
        } finally {
            unlink($path);
        }
    }

    public function testKnownInterfacePreservesOnlyDeclaredPositions(): void
    {
        $source = '<?php namespace App; interface Contract { function run($input); } class Worker implements Contract { function run($input) {} }';
        self::assertSame([], $this->findings($source));
        $extra = str_replace('function run($input) {}', 'function run($input, $extra = null) {}', $source);
        $findings = $this->findings($extra);
        self::assertCount(1, $findings);
        self::assertStringContainsString('$extra', $findings[0]->message);
        self::assertSame([], $this->findings($extra, ['App\\Worker::run']));
    }

    public function testNativeContractsPreserveOnlyInheritedPositions(): void
    {
        $source = '<?php namespace App; use ArrayAccess as Indexed; class Answers implements Indexed { public function offsetSet($offset, $value) { throw new \\LogicException; } public function offsetUnset($offset) { throw new \\LogicException; } }';
        self::assertSame([], $this->findings($source));
        self::assertSame([], $this->findings('<?php $answers = new class implements \\ArrayAccess { public function offsetSet($offset, $value) {} };'));
        self::assertSame([], $this->findings('<?php interface Indexed extends \\ArrayAccess {} class Answers implements Indexed { public function offsetSet($offset, $value) {} }'));
        self::assertSame([], $this->findings('<?php class Answers extends \\ArrayObject { public function offsetSet($offset, $value) {} }'));
        self::assertSame([], $this->findings('<?php class Capture extends \\php_user_filter { public function filter($in, $out, &$consumed, $closing) {} }'));
        self::assertCount(1, $this->findings('<?php class Capture extends \\php_user_filter { public function filter($in, $out, &$consumed, $closing, $extra = null) {} }'));
        self::assertSame([], $this->findings('<?php class Sessions implements \\SessionHandlerInterface { public function open($path, $name): bool { return true; } public function close(): bool { return true; } public function read($id): string { return ""; } public function write($id, $data): bool { return true; } public function destroy($id): bool { return true; } public function gc($lifetime): int { return 0; } }'));
        $extra = $this->findings('<?php class Answers implements \\ArrayAccess { public function offsetSet($offset, $value, $extra = null) {} }');
        self::assertCount(1, $extra);
        self::assertStringContainsString('$extra', $extra[0]->message);
        self::assertCount(2, $this->findings('<?php class Answers { public function offsetSet($offset, $value) {} }'));
        self::assertCount(2, $this->findings('<?php namespace App; interface ArrayAccess {} class Answers implements ArrayAccess { public function offsetSet($offset, $value) {} }'));
        self::assertCount(1, $this->findings('<?php class Visitor implements \\PhpParser\\NodeVisitor { public function enterNode($node) {} }'));
        self::assertCount(1, $this->findings('<?php class Answers extends \\ArrayObject { public function __construct($input) {} }'));
    }

    public function testStandardRunnerResolvesNativeContractsAcrossFiles(): void
    {
        $directory = sys_get_temp_dir().'/soda-native-parents-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $contract = $directory.'/Contract.php';
        $implementation = $directory.'/Answers.php';
        file_put_contents($contract, '<?php namespace App; interface Indexed extends \\ArrayAccess {}');
        file_put_contents($implementation, '<?php namespace App; class Answers implements Indexed { public function offsetSet($offset, $value, $extra = null) {} }');

        try {
            foreach ([[$contract, $implementation], [$implementation, $contract]] as $files) {
                $result = (new Runner)->check($files, RuleCatalog::standard());
                $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
                self::assertCount(1, $findings);
                self::assertSame($implementation, $findings[0]->file);
                self::assertStringContainsString('$extra', $findings[0]->message);
            }
        } finally {
            unlink($contract);
            unlink($implementation);
            rmdir($directory);
        }
    }

    public function testStandardRunnerResolvesNativeContractsWithoutAutoloadingApplicationCode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-native-contracts-');
        self::assertIsString($path);
        file_put_contents($path, '<?php class Answers implements \\ArrayAccess { public function offsetSet($offset, $value) { throw new \\LogicException; } public function offsetUnset($offset) { throw new \\LogicException; } } class Local extends SodaUnloadedParent { public function offsetSet($offset, $value) {} }');
        $autoloaded = [];
        $autoload = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($autoload);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
            self::assertCount(2, $findings);
            self::assertSame('Local', $findings[0]->class);
            self::assertSame('Local', $findings[1]->class);
            self::assertNotContains('SodaUnloadedParent', $autoloaded);
        } finally {
            spl_autoload_unregister($autoload);
            unlink($path);
        }
    }

    public function testAnonymousClassesPreserveOnlyInheritedPositions(): void
    {
        $source = '<?php interface Caster { function get($model, $key); } $caster = new class implements Caster { function get($model, $key) {} };';
        self::assertSame([], $this->findings($source));
        $extra = str_replace('function get($model, $key) {}', 'function get($model, $key, $extra = null) {}', $source);
        $findings = $this->findings($extra);
        self::assertCount(1, $findings);
        self::assertStringContainsString('$extra', $findings[0]->message);
        self::assertSame([], $this->findings('<?php abstract class BaseCaster { abstract function get($input); } $caster = new class extends BaseCaster { function get($input) {} };'));
        self::assertCount(2, $this->findings('<?php $caster = new class { function get($model, $key) {} };'));
        self::assertCount(1, $this->findings('<?php interface Caster { function get($input); } class Outer implements Caster { function get($input) { return new class { function get($input) {} }; } }'));
    }

    public function testStandardRunnerSeparatesAnonymousContractsAcrossFiles(): void
    {
        $directory = sys_get_temp_dir().'/soda-anonymous-contracts-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $contract = $directory.'/Contract.php';
        $first = $directory.'/First.php';
        $second = $directory.'/Second.php';
        file_put_contents($contract, '<?php interface FirstContract { function run($input); } interface OtherContract {}');
        file_put_contents($first, '<?php $item = new class implements FirstContract { function run($input) {} };');
        file_put_contents($second, '<?php $item = new class implements OtherContract { function run($input) {} };');

        try {
            foreach ([[$first, $second, $contract], [$contract, $second, $first]] as $files) {
                $result = (new Runner)->check($files, RuleCatalog::standard());
                $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
                self::assertCount(1, $findings);
                self::assertSame($second, $findings[0]->file);
            }
        } finally {
            unlink($contract);
            unlink($first);
            unlink($second);
            rmdir($directory);
        }
    }

    public function testCallbackPositionsBeforeLastReadAreRequired(): void
    {
        foreach ([
            'function ($type, $buffer) { return $buffer; }',
            'fn ($type, $buffer) => $buffer',
            'function ($app, $name, $config) { return $config; }',
        ] as $callback) {
            self::assertSame([], $this->findings('<?php consume('.$callback.');'));
        }
        self::assertCount(1, $this->findings('<?php consume(fn ($first, $used, $last) => $used);'));
        self::assertCount(2, $this->findings('<?php consume(fn ($first, $last) => 1);'));
        self::assertCount(1, $this->findings('<?php function own($unused, $used) { return $used; }'));
        self::assertCount(2, $this->findings('<?php consume(fn ($unused, $overwritten) => $overwritten = 1);'));
    }

    public function testRegisteredNativeStreamHooksPreserveOnlyNativePositions(): void
    {
        $hooks = 'public function stream_open($path, $mode, $options, &$opened) {} public function stream_set_option($option, $one, $two) {} public function url_stat($path, $flags) {}';
        self::assertCount(9, $this->findings('<?php class Stream { '.$hooks.' }'));
        self::assertSame([], $this->findings('<?php class Stream { '.$hooks.' public static function register() { stream_wrapper_register("test", __CLASS__); } }'));
        self::assertCount(1, $this->findings('<?php class Stream { public function url_stat($path, $flags, $extra) {} public static function register() { stream_wrapper_register("test", __CLASS__); } }'));
        self::assertCount(2, $this->findings('<?php class Stream { public function url_stat($path, $flags) {} public function other() { return new class { public function register() { stream_wrapper_register("test", __CLASS__); } }; } }'));
    }

    public function testPackageInterfaceContractDoesNotNeedAnInstalledAutoloader(): void
    {
        $root = sys_get_temp_dir().'/soda-package-inputs-'.bin2hex(random_bytes(6));
        mkdir($root.'/src', recursive: true);
        $manifest = $root.'/composer.json';
        $scope = $root.'/src/Scope.php';
        $own = $root.'/src/OwnScope.php';
        file_put_contents($manifest, json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_THROW_ON_ERROR));
        file_put_contents($scope, '<?php namespace App; throw new \\RuntimeException("Must not execute"); interface Scope { public function apply($value); }');
        file_put_contents($own, '<?php namespace App; class OwnScope implements Scope { public function apply($value, $extra = null) {} }');

        try {
            $result = (new Runner)->check([$own], [new NoUnusedParameters]);
            self::assertCount(1, $result->violations);
            self::assertStringContainsString('$extra', $result->violations[0]->message);
        } finally {
            foreach ([$manifest, $scope, $own] as $path) {
                unlink($path);
            }
            rmdir($root.'/src');
            rmdir($root);
        }
    }

    private function findings(string $source, array $contracts = []): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $nodes = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($nodes);

        return iterator_to_array((new NoUnusedParameters($contracts))->checkFile(new FileFacts('/project/input.php', $source, $nodes, [])));
    }
}
