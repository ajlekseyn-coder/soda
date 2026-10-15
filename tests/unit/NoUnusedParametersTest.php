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
        self::assertCount(1, $this->findings('<?php namespace PhpParser { interface NodeVisitor {} } namespace App { class Visitor implements \\PhpParser\\NodeVisitor { public function enterNode($node) {} } }'));
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
        file_put_contents($path, '<?php class Answers implements \\ArrayAccess { public function offsetSet($offset, $value) { throw new \\LogicException; } public function offsetUnset($offset) { throw new \\LogicException; } } class Local extends SodaUnloadedParent { private function offsetSet($offset, $value) {} }');
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

    public function testStandardRunnerRequiresCompleteInheritedContracts(): void
    {
        $root = sys_get_temp_dir().'/soda-unknown-inputs-'.bin2hex(random_bytes(6));
        mkdir($root);
        $contract = $root.'/Contracts.php';
        $own = $root.'/Workers.php';
        file_put_contents($contract, '<?php namespace App; interface EmptyContract {} interface KnownContract { public function run($value); } interface UnresolvedContract extends \Missing\Protocol {} trait SharedWorker { public function shared($value) {} }');
        file_put_contents($own, '<?php namespace App; class UnknownWorker implements UnresolvedContract { use SharedWorker; public function run($value) {} private function own($private) {} } class OwnWorker implements EmptyContract { public function run($owned) {} } class KnownWorker implements KnownContract { public function run($value, $extra = null) {} } class UnknownConstructor extends \Missing\ParentType { public function __construct($input) {} } class OwnConstructor { public function __construct($input) {} }');
        $autoloaded = [];
        $autoload = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($autoload);

        try {
            foreach ([[$contract, $own], [$own, $contract]] as $paths) {
                $result = (new Runner)->check($paths, RuleCatalog::standard());
                self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
                self::assertCount(4, $findings);
                self::assertSame(['own', 'run', 'run', '__construct'], $findings->map(static fn ($violation): ?string => $violation->method)->all());
                self::assertStringContainsString('$extra', $findings[2]->message);
            }
            self::assertSame([], $autoloaded);
        } finally {
            spl_autoload_unregister($autoload);
            unlink($contract);
            unlink($own);
            rmdir($root);
        }
    }

    #[DataProvider('inheritedCompleteness')]
    public function testMissingDeclarationsDoNotProveOwnedSignatures(string $source, int $expected): void
    {
        self::assertCount($expected, $this->findings('<?php '.$source));
    }

    public static function inheritedCompleteness(): iterable
    {
        yield 'unknown interface constructor' => ['class Record implements ExternalContract { public function __construct($input) {} }', 0];
        yield 'unknown parent constructor' => ['class Record extends ExternalRecord { public function __construct($input) {} }', 0];
        yield 'private constructor remains owned' => ['class Record extends ExternalRecord { private function __construct($input) {} }', 1];
        yield 'unknown parent through a known ancestor' => ['class Base extends ExternalRecord {} class Record extends Base { protected function run($input) {} }', 0];
        yield 'unknown trait requirement' => ['class Record { use ExternalTrait; public function run($input) {} }', 0];
        yield 'same method on an independent class remains owned' => ['class Unknown implements ExternalContract { public function run($input) {} } class Own { public function run($input) {} }', 1];
        yield 'known empty interface constructor remains owned' => ['interface Contract {} class Record implements Contract { public function __construct($input) {} }', 1];
        yield 'known empty parent remains owned' => ['class Base {} class Record extends Base { protected function run($input) {} }', 1];
        yield 'known empty cyclic ancestry remains owned' => ['interface First extends Second {} interface Second extends First {} class Record implements First { public function run($input) {} }', 1];
        yield 'cycle with missing ancestor is incomplete' => ['interface First extends Second {} interface Second extends First, ExternalContract {} class Record implements First { public function run($input) {} }', 0];
        yield 'anonymous unknown interface' => ['$record = new class implements ExternalContract { public function run($input) {} };', 0];
        yield 'trait input required by one unresolved consumer' => ['trait Shared { public function run($input) {} } class Unknown implements ExternalContract { use Shared; } class Own { use Shared; }', 0];
        yield 'private trait input remains owned' => ['trait Shared { private function run($input) {} } class Unknown implements ExternalContract { use Shared; }', 1];
    }

    #[DataProvider('factoryReturnDeclarations')]
    public function testObservedFactoryDispatchPreservesOnlyItsFamilyAndSlots(string $declaration, string $imports = 'use Contracts\\Handler as Selected;'): void
    {
        $root = sys_get_temp_dir().'/soda-dispatch-inputs-'.bin2hex(random_bytes(6));
        mkdir($root);
        $base = $root.'/Base.php';
        $dispatch = $root.'/Dispatch.php';
        $handlers = $root.'/Handlers.php';
        file_put_contents($base, '<?php namespace Contracts; abstract class Handler {} abstract class OtherHandler {}');
        file_put_contents($dispatch, '<?php namespace App; '.$imports.' final class Dispatcher { public function dispatch(string $class, object $node) { $handler = $this->make($class); return $handler->resolve($node); } '.$declaration.' { return new $class; } } final class OtherFactory { public function make(string $class): \\Contracts\\OtherHandler { return new $class; } }');
        file_put_contents($handlers, '<?php namespace App; final class Quiet extends \\Contracts\\Handler { public function resolve(object $node, $extra = null) { return 1; } public function own($unused) {} } final class Standalone { public function resolve($input) {} } final class Other extends \\Contracts\\OtherHandler { public function resolve($input) {} }');

        try {
            foreach ([[$base, $dispatch, $handlers], [$handlers, $dispatch, $base]] as $paths) {
                $result = (new Runner)->check($paths, RuleCatalog::standard());
                self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
                self::assertCount(4, $findings);
                self::assertStringContainsString('$extra', $findings[0]->message);
                self::assertSame(['App\\Quiet', 'App\\Quiet', 'App\\Standalone', 'App\\Other'], $findings->map(static fn ($violation): ?string => $violation->class)->all());
            }
        } finally {
            foreach ([$base, $dispatch, $handlers] as $path) {
                unlink($path);
            }
            rmdir($root);
        }
    }

    public static function factoryReturnDeclarations(): iterable
    {
        yield 'native bound' => ['private function make(string $class): Selected'];
        yield 'aliased documented bound' => ['/** @return Selected */ private function make(string $class)'];
        yield 'qualified documented bound' => ['/** @return \\Contracts\\Handler */ private function make(string $class)'];
        yield 'grouped import' => ['/** @return Selected */ private function make(string $class)', 'use Contracts\\{Handler as Selected};'];
    }

    #[DataProvider('factoryBindingCases')]
    public function testFactoryDispatchBindingBoundaries(string $binding, string $factory, string $invocation, int $expected): void
    {
        $source = '<?php namespace Sample; abstract class Bound {} class Known extends Bound { public function resolve($input) { return $input; } } class Quiet extends Bound { public function resolve($unused, $extra = null) {} } class Other { public function resolve($input) { return $input; } } final class Dispatch { public function run($class, $node) { '.$binding.' return '.$invocation.'; } '.$factory.' }';
        $path = tempnam(sys_get_temp_dir(), 'soda-dispatch-binding-');
        file_put_contents($path, $source);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters' && $violation->class === 'Sample\\Quiet')->values();
            self::assertCount($expected, $findings);
            self::assertStringContainsString('$extra', $findings[$expected - 1]->message);
        } finally {
            unlink($path);
        }
    }

    public static function factoryBindingCases(): iterable
    {
        $factory = 'private function make($class): Bound { return new $class; }';
        $binding = '$handler = $this->make($class);';
        $call = '$handler->resolve($node)';
        yield 'open dynamic input' => [$binding, $factory, $call, 1];
        yield 'direct finite class' => ['$handler = $this->make(Known::class);', $factory, $call, 2];
        yield 'finite caller alias' => ['$class = Known::class; '.$binding, $factory, $call, 2];
        yield 'finite factory alias chain' => [$binding, 'private function make($class): Bound { $selected = Known::class; $class = $selected; return new $class; }', $call, 2];
        yield 'omitted finite default' => ['$handler = $this->make();', 'private function make($class = Known::class): Bound { return new $class; }', $call, 2];
        yield 'concrete factory' => [$binding, 'private function make($class): Bound { return new Known; }', $call, 2];
        yield 'unreachable computed return' => [$binding, 'private function make($class): Bound { return new Known; return new $class; }', $call, 2];
        yield 'receiver reassignment' => [$binding.' $handler = new Other;', $factory, $call, 2];
        yield 'conditional receiver reassignment' => [$binding.' if ($node) { $handler = new Other; }', $factory, $call, 2];
        yield 'unknown reference escape' => [$binding.' rebind($handler);', $factory, $call, 2];
        yield 'reference alias' => [$binding.' $alias =& $handler; $alias = new Other;', $factory, $call, 2];
        yield 'reference capture' => [$binding.' $later = function () use (&$handler) { $handler = new Other; }; $later();', $factory, $call, 2];
        yield 'independent nested reference capture' => [$binding.' $object = new class { function operation() { $handler = null; return function () use (&$handler) {}; } };', $factory, $call, 1];
        yield 'shadowed nested reference capture' => [$binding.' $later = function ($handler) { return function () use (&$handler) {}; };', $factory, $call, 1];
        yield 'nested value capture' => [$binding.' $later = function () use ($handler) { return function () use (&$handler) {}; };', $factory, $call, 1];
        yield 'nested reference capture chain' => [$binding.' $later = function () use (&$handler) { return function () use (&$handler) {}; };', $factory, $call, 2];
        yield 'variable variable write' => [$binding.' $name = "handler"; $$name = new Other;', $factory, $call, 2];
        yield 'local symbol-table extraction' => [$binding.' extract(["handler" => new Other]);', $factory, $call, 2];
        yield 'included local scope' => [$binding.' include "binding.php";', $factory, $call, 2];
        yield 'evaluated local scope' => [$binding.' eval($class);', $factory, $call, 2];
        yield 'unrelated nested include' => [$binding.' $later = function () { include "binding.php"; };', $factory, $call, 1];
        yield 'named actual argument' => [$binding, $factory, '$handler->resolve(unused: $node)', 2];
        yield 'unpacked actual arguments' => [$binding, $factory, '$handler->resolve(...[$node])', 2];
        yield 'first-class callable reference' => [$binding, $factory, '$handler->resolve(...)', 2];
        yield 'guard without invocation' => [$binding, $factory, 'method_exists($handler, "resolve")', 2];
        yield 'nested shadowed receiver invocation' => [$binding, $factory, 'function ($handler) use ($node) { return $handler->resolve($node); }', 2];
        yield 'native qualified guard' => [$binding.' \\method_exists($handler, "resolve");', $factory, $call, 1];
        yield 'native fallback guard' => [$binding.' method_exists($handler, "resolve");', $factory, $call, 1];
        yield 'shadowed fallback guard' => [$binding.' method_exists($handler, "resolve");', $factory.' function unrelated() { function method_exists(&$handler, $method) { $handler = new Other; } }', $call, 2];
        yield 'unknown factory result' => [$binding, 'private function make($class) { return new $class; }', $call, 2];
        yield 'wrong documented family' => [$binding, '/** @return Other */ private function make($class) { return new $class; }', $call, 2];
        yield 'prose is not a return annotation' => [$binding, '/** Mention @return Bound in explanatory prose. */ private function make($class) { return new $class; }', $call, 2];
        yield 'unknown native union' => [$binding, 'private function make($class): Bound|Other { return new $class; }', $call, 2];
    }

    public function testVirtualDocumentedFactoriesPreserveSelectedTraitSlotsAcrossBranches(): void
    {
        $root = sys_get_temp_dir().'/soda-virtual-inputs-'.bin2hex(random_bytes(6));
        mkdir($root);
        $base = $root.'/Contracts.php';
        $dispatch = $root.'/Dispatcher.php';
        $handlers = $root.'/Handlers.php';
        file_put_contents($base, '<?php namespace Contracts; abstract class Handler {}');
        file_put_contents($dispatch, <<<'PHP'
<?php
namespace App;
use Contracts\Handler as Selected;
class Dispatcher {
    public function dispatch(object $node) {
        $handler = $this->make($node);
        try {
            if (condition()) {
                return method_exists($handler, 'resolveForCondition') ? $handler->resolveForCondition($node) : null;
            }
            return $handler->resolve($node);
        } catch (\Throwable $error) {
            return null;
        }
    }
    /** @return Selected */
    protected function make(object $node) {
        $class = $this->choose($node);
        return new $class;
    }
    /** @return class-string<Selected> */
    protected function choose(object $node) { return classNameFor($node); }
}
PHP);
        file_put_contents($handlers, <<<'PHP'
<?php
namespace App;
trait SharedBody { public function resolve($input, $extra = null) {} }
trait ExcludedBody { public function resolve($excluded) {} }
trait HiddenBody { public function resolve($hidden) {} }
trait ReplacedBody { public function resolve($replaced) {} }
class Quiet extends \Contracts\Handler {
    use SharedBody, ExcludedBody {
        SharedBody::resolve insteadof ExcludedBody;
        SharedBody::resolve as resolveForCondition;
    }
}
class Hidden extends \Contracts\Handler { use HiddenBody { resolve as private; } }
class Replaced extends \Contracts\Handler {
    use ReplacedBody;
    public function resolve($input) { return $input; }
}
PHP);
        $autoloaded = [];
        $autoload = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($autoload);

        try {
            foreach ([[$base, $dispatch, $handlers], [$handlers, $dispatch, $base]] as $paths) {
                $result = (new Runner)->check($paths, RuleCatalog::standard());
                self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
                self::assertCount(4, $findings);
                self::assertSame(['App\\SharedBody', 'App\\ExcludedBody', 'App\\HiddenBody', 'App\\ReplacedBody'], $findings->map(static fn ($violation): ?string => $violation->class)->all());
                self::assertStringContainsString('$extra', $findings[0]->message);
            }
            self::assertSame([], $autoloaded);
        } finally {
            spl_autoload_unregister($autoload);
            foreach ([$base, $dispatch, $handlers] as $path) {
                unlink($path);
            }
            rmdir($root);
        }
    }

    public function testInterpolatedOwnDispatchPreservesOnlySelectedTraitPositions(): void
    {
        $root = sys_get_temp_dir().'/soda-own-inputs-'.bin2hex(random_bytes(6));
        mkdir($root);
        $dispatch = $root.'/Dispatch.php';
        $steps = $root.'/Steps.php';
        file_put_contents($dispatch, '<?php namespace App; trait Dispatch { public function run($kind, $input) { $method = "render{$kind}"; return $this->$method($input); } } class Printer { use Dispatch, Steps; } class Standalone { protected function renderSkip($unrelated) {} }');
        file_put_contents($steps, '<?php namespace App; trait Steps { protected function renderSkip($unused, $extra = null) {} protected function other($unrelated) {} }');

        try {
            foreach ([[$dispatch, $steps], [$steps, $dispatch]] as $paths) {
                $result = (new Runner)->check($paths, RuleCatalog::standard());
                self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters')->values();
                self::assertCount(3, $findings);
                $messages = $findings->map(static fn ($violation): string => $violation->message)->all();
                self::assertCount(1, array_filter($messages, static fn (string $message): bool => str_contains($message, '$extra')));
                self::assertCount(2, array_filter($messages, static fn (string $message): bool => str_contains($message, '$unrelated')));
            }
        } finally {
            unlink($dispatch);
            unlink($steps);
            rmdir($root);
        }
    }

    #[DataProvider('ownSelectorCases')]
    public function testOwnSelectorBoundaries(string $body, int $expected, string $consumers = 'class Printer { use Dispatch, Steps; }'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-selector-inputs-');
        file_put_contents($path, '<?php namespace App; trait Dispatch { public function run($kind, $input, $other) { '.$body.' } } trait Steps { protected function renderSkip($unused, $extra = null) {} } '.$consumers);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters' && $violation->class === 'App\\Steps')->values();
            self::assertCount($expected, $findings);
            if ($expected !== 0) {
                self::assertStringContainsString('$extra', $findings[$expected - 1]->message);
            }
        } finally {
            unlink($path);
        }
    }

    public static function ownSelectorCases(): iterable
    {
        $binding = '$method = "render{$kind}";';
        $call = 'return $this->$method($input);';
        yield 'bounded interpolation' => [$binding.$call, 1];
        yield 'native guard assignment' => ['if (method_exists($this, $method = "render{$kind}")) { '.$call.' }', 1];
        yield 'native elseif guard assignment' => ['if (condition()) { return null; } elseif (method_exists($this, $method = "render{$kind}")) { '.$call.' }', 1];
        yield 'failed native guard body' => ['if (method_exists($this, $method = "render{$kind}")) { return null; } else { '.$call.' }', 2];
        yield 'wrong prefix' => ['$method = "other{$kind}";'.$call, 2];
        yield 'closed literal suffix' => ['$kind = "Other";'.$binding.$call, 2];
        yield 'closed aliased suffix' => ['$suffix = "Other"; $kind = $suffix;'.$binding.$call, 2];
        yield 'unbounded selector' => ['$method = $kind;'.$call, 2];
        yield 'selector overwrite' => [$binding.' $method = $kind;'.$call, 2];
        yield 'unknown selector escape' => [$binding.' change($method);'.$call, 2];
        yield 'selector reference capture' => [$binding.' $later = function () use (&$method) { $method = "other"; };'.$call, 2];
        yield 'selector symbol-table write' => [$binding.' extract($input);'.$call, 2];
        yield 'selector include scope' => [$binding.' include "selector.php";'.$call, 2];
        yield 'nested assignment is not an outer binding' => ['$later = function () use ($kind) { '.$binding.' };'.$call, 2];
        yield 'nested invocation is not an outer protocol' => [$binding.'return function () use ($method, $input) { '.$call.' };', 2];
        yield 'conditional binding does not dominate' => ['if (condition()) { '.$binding.' }'.$call, 2];
        yield 'assignment after call' => [$call.$binding, 2];
        yield 'another receiver' => [$binding.'return $other->$method($input);', 2];
        yield 'named invocation' => [$binding.'return $this->$method(unused: $input);', 2];
        yield 'unpacked invocation' => [$binding.'return $this->$method(...[$input]);', 2];
        yield 'first-class reference' => [$binding.'return $this->$method(...);', 2];
        yield 'guard without invocation' => ['return method_exists($this, $method = "render{$kind}");', 2];
        yield 'two observed slots' => [$binding.'return $this->$method($input, $kind);', 0];
        yield 'excluded caller body' => [$binding.$call, 2, 'class Printer { use Dispatch, Steps; public function run($kind, $input, $other) { return $input; } }'];
        yield 'excluded caller trait' => [$binding.$call, 2, 'trait OtherDispatch { public function run($kind, $input, $other) { return $input; } } class Printer { use Dispatch, OtherDispatch, Steps { OtherDispatch::run insteadof Dispatch; } }'];
        yield 'replaced target body' => [$binding.$call, 2, 'class Printer { use Dispatch, Steps; protected function renderSkip($input) { return $input; } }'];
        yield 'private selected target' => [$binding.$call, 2, 'class Printer { use Dispatch, Steps { renderSkip as private; } }'];
        yield 'selected alias name' => ['$method = "present{$kind}";'.$call, 1, 'class Printer { use Dispatch, Steps { renderSkip as presentSkip; } }'];
        yield 'unrelated trait consumers' => [$binding.$call, 2, 'class Printer { use Steps; } class OtherPrinter { use Dispatch; }'];
        yield 'shadowed native guard' => ['if (method_exists($this, $method = "render{$kind}")) { '.$call.' }', 2, 'function method_exists($object, &$name) { $name = "other"; return true; } class Printer { use Dispatch, Steps; }'];
    }

    private function findings(string $source, array $contracts = []): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $nodes = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($nodes);

        return iterator_to_array((new NoUnusedParameters($contracts))->checkFile(new FileFacts('/project/input.php', $source, $nodes, [])));
    }
}
