<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Architecture\NoCatchAllExceptions;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoCatchAllExceptionsTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('catchBodies')]
    public function testReportsOnlyBroadCatchesWithoutAnExplicitOutcome(string $body, int $expected): void
    {
        $source = 'function run() { try { work(); } catch (\\Throwable $error) { '.$body.' } }';
        $file = new FileFacts('/handler.php', '', $this->parseSnippet($source), []);
        $violations = iterator_to_array((new NoCatchAllExceptions)->checkFile($file));

        $this->assertCount($expected, $violations);
        if ($expected > 0) {
            $this->assertSame('no_catch_all_exceptions', $violations[0]->rule);
            $this->assertSame(1, $violations[0]->line);
        }
    }

    public static function catchBodies(): \Iterator
    {
        yield 'explicit failure exit' => ['report($error); exit(1);', 0];
        yield 'explicit success exit is a chosen outcome' => ['exit(0);', 0];
        yield 'explicit exit message' => ['die("Failed");', 0];
        yield 'computed exit status' => ['exit(status($error));', 0];
        yield 'bare exit terminates the process' => ['exit;', 0];
        yield 'conditional exit leaves a continuing path' => ['if (fatal($error)) { exit(1); }', 1];
        yield 'exit and fallback cover both branches' => ['if (fatal($error)) { exit(1); } else { return null; }', 0];
        yield 'exit in closure is not catch outcome' => ['$terminate = function () { exit(1); };', 1];
        yield 'void return before exit still hides failure' => ['if (recoverable($error)) { return; } exit(1);', 1];
        yield 'unreachable bare return after exit' => ['exit(1); return;', 0];
        yield 'unreachable bare return after fallback' => ['return null; return;', 0];
        yield 'unreachable bare return after rethrow' => ['throw $error; return;', 0];
        yield 'nested fallback returns on success and failure' => ['try { return recover($error); } catch (RuntimeException $nested) { return []; }', 0];
        yield 'nested fallback translates failure' => ['try { return recover($error); } catch (RuntimeException $nested) { throw $nested; }', 0];
        yield 'every nested catch has an outcome' => ['try { return recover($error); } catch (LogicException $nested) { return false; } catch (RuntimeException $nested) { return []; }', 0];
        yield 'nested try can still fall through' => ['try { recover($error); } catch (RuntimeException $nested) { return []; }', 1];
        yield 'one nested catch can still fall through' => ['try { return recover($error); } catch (LogicException $nested) { report($nested); } catch (RuntimeException $nested) { return []; }', 1];
        yield 'nested bare return hides failure' => ['try { return recover($error); } catch (RuntimeException $nested) { return; }', 1];
        yield 'finally can override nested outcomes' => ['try { return recover($error); } catch (RuntimeException $nested) { return []; } finally { return; }', 1];
        yield 'closure outcomes do not terminate a nested try' => ['try { $fallback = fn () => null; } catch (RuntimeException $nested) { return []; }', 1];
        yield 'closure jumps do not escape a nested try' => ['try { $callback = function () { foreach ([1] as $item) { break; } }; return recover($error); } catch (RuntimeException $nested) { return []; }', 0];
        yield 'empty catch' => ['', 1];
        yield 'logging then continuing' => ['report($error);', 1];
        yield 'void guard before fallback' => ['if (recoverable($error)) { return; } return null;', 1];
        yield 'closure void return does not affect fallback' => ['$callback = function () { return; }; return null;', 0];
        yield 'bare return' => ['return;', 1];
        yield 'null fallback' => ['return null;', 0];
        yield 'false fallback' => ['return false;', 0];
        yield 'structured error' => ['return ["error" => $error->getMessage()];', 0];
        yield 'cleanup then rethrow' => ['cleanup(); throw $error;', 0];
        yield 'translated exception' => ['throw new RuntimeException("Failed", 0, $error);', 0];
        yield 'all branches handled' => ['if (recoverable($error)) { return null; } else { throw $error; }', 0];
        yield 'elseif handled' => ['if (first($error)) { return null; } elseif (second($error)) { return false; } else { throw $error; }', 0];
        yield 'one branch continues' => ['if (recoverable($error)) { return null; } else { report($error); }', 1];
        yield 'conditional return only' => ['if (recoverable($error)) { return null; }', 1];
        yield 'guard then rethrow' => ['if (recoverable($error)) { return null; } throw $error;', 0];
        yield 'closure return is not catch outcome' => ['$fallback = fn () => null;', 1];
        yield 'nested closure return' => ['$fallback = function () { return null; };', 1];
    }

    #[DataProvider('nestedJumps')]
    public function testNestedFallbackCannotHideAnEscapingJump(string $body): void
    {
        $source = 'function run() { foreach ([1] as $item) { try { work(); } catch (\\Throwable $error) { '.$body.' } } done: report(); }';
        $file = new FileFacts('/handler.php', '', $this->parseSnippet($source), []);

        self::assertCount(1, iterator_to_array((new NoCatchAllExceptions)->checkFile($file)));
    }

    public static function nestedJumps(): \Iterator
    {
        yield 'break before unreachable return' => ['try { break; return recover(); } catch (RuntimeException $nested) { return []; }'];
        yield 'continue before unreachable return' => ['try { continue; return recover(); } catch (RuntimeException $nested) { return []; }'];
        yield 'conditional break before return' => ['try { if (stop()) { break; } return recover(); } catch (RuntimeException $nested) { return []; }'];
        yield 'goto before unreachable return' => ['try { goto done; return recover(); } catch (RuntimeException $nested) { return []; }'];
        yield 'jump in recovery path' => ['try { return recover(); } catch (RuntimeException $nested) { continue; return []; }'];
    }

    #[DataProvider('contractScopes')]
    public function testContractsExemptOnlyTheNamedDeclaredMethod(string $source, array $contracts, int $expected): void
    {
        $nodes = (new NodeTraverser(
            new NameResolver,
            new ParentConnectingVisitor,
        ))->traverse($this->parseSnippet($source));
        $file = new FileFacts('/handler.php', '', $nodes, []);

        $this->assertCount($expected, iterator_to_array((new NoCatchAllExceptions(contracts: $contracts))->checkFile($file)));
    }

    public static function contractScopes(): \Iterator
    {
        yield 'exact method only' => [
            'namespace App; class Client { function cleanup() { try { work(); } catch (\\Throwable $e) {} } function run() { try { work(); } catch (\\Throwable $e) {} } }',
            ['App\\Client::cleanup'], 1,
        ];
        yield 'case insensitive method identity' => [
            'namespace App; class Client { function cleanup() { try { work(); } catch (\\Throwable $e) {} } }',
            ['app\\client::CLEANUP'], 0,
        ];
        yield 'same name in another class' => [
            'namespace App; class Other { function cleanup() { try { work(); } catch (\\Throwable $e) {} } }',
            ['App\\Client::cleanup'], 1,
        ];
        yield 'overridden method is not exempt' => [
            'namespace App; class Client { function cleanup() { try { work(); } catch (\\Throwable $e) {} } } class Child extends Client { function cleanup() { try { work(); } catch (\\Throwable $e) {} } }',
            ['App\\Client::cleanup'], 1,
        ];
        yield 'wildcards do not exempt methods' => [
            'namespace App; class Client { function cleanup() { try { work(); } catch (\\Throwable $e) {} } }',
            ['App\\*::cleanup'], 1,
        ];
        yield 'anonymous class inside a contract is not exempt' => [
            'namespace App; class Client { function cleanup() { return new class { function cleanup() { try { work(); } catch (\\Throwable $e) {} } }; } }',
            ['App\\Client::cleanup'], 1,
        ];
        yield 'nested closure is not exempt' => [
            'namespace App; class Client { function cleanup() { return function () { try { work(); } catch (\\Throwable $e) {} }; } }',
            ['App\\Client::cleanup'], 1,
        ];
        yield 'named nested function is not exempt' => [
            'namespace App; class Client { function cleanup() { function nested() { try { work(); } catch (\\Throwable $e) {} } } }',
            ['App\\Client::cleanup'], 1,
        ];
    }

    public function testSpecificExceptionsAreAllowedWithoutAnOutcome(): void
    {
        $file = new FileFacts('/handler.php', '', $this->parseSnippet(
            'try { work(); } catch (RuntimeException $error) { report($error); }',
        ), []);

        $this->assertSame([], iterator_to_array((new NoCatchAllExceptions)->checkFile($file)));
    }

    #[DataProvider('sourceTerminalCalls')]
    public function testSourceTerminalCallsRequireAnExactNativeOutcome(string $declarations, string $call, int $expected, bool $classCaller = false): void
    {
        $root = sys_get_temp_dir().'/soda-terminal-catch-'.bin2hex(random_bytes(6));
        mkdir($root);
        $declaration = $root.'/Declarations.php';
        $caller = $root.'/Caller.php';
        file_put_contents($declaration, '<?php namespace App; '.$declarations);
        $target = 'function target($service, $class, $method) { try { work(); } catch (\\Throwable $error) { '.$call.' } }';
        $target = $classCaller ? 'class Consumer { public '.$target.' }' : $target;
        file_put_contents($caller, '<?php namespace App; use App\\Terminator as Boundary;'."\n".$target.'
            function genuinelyContinues() { try { work(); } catch (\\Throwable $error) { report($error); } }
            function throwsDirectly() { try { work(); } catch (\\Throwable $error) { throw $error; } }');

        try {
            foreach ([[new NoCatchAllExceptions], RuleCatalog::standard()] as $checks) {
                foreach ([[$declaration, $caller], [$caller, $declaration]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                    $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_catch_all_exceptions')->values();
                    $genuine = $findings->filter(static fn ($row): bool => $row->line === 3)->values();
                    self::assertCount(1, $genuine);
                    self::assertSame($caller, $genuine[0]->file);
                    self::assertNull($genuine[0]->class);
                    self::assertNull($genuine[0]->method);
                    self::assertSame(1, $genuine[0]->value);
                    self::assertSame(0, $genuine[0]->threshold);
                    self::assertSame('A broad catch continues without returning a value or throwing. Make the failure outcome explicit.', $genuine[0]->message);
                    $targets = $findings->filter(static fn ($row): bool => $row->line === 2)->values();
                    self::assertCount($expected, $targets);
                    self::assertCount($expected + 1, $findings);
                    if ($expected !== 0) {
                        self::assertSame([...$genuine[0]->toArray(), 'line' => 2], $targets[0]->toArray());
                    }
                }
            }
        } finally {
            unlink($declaration);
            unlink($caller);
            rmdir($root);
        }
    }

    public static function sourceTerminalCalls(): iterable
    {
        $exit = 'class Terminator { public static function stop($error) { report($error); exit(1); } }';

        yield 'explicit class native exit body' => [$exit, 'Terminator::stop($error);', 0];
        yield 'class caller observes exact static terminal' => [$exit, 'Terminator::stop($error);', 0, true];
        yield 'imported alias identifies native exit body' => [$exit, 'Boundary::stop($error);', 0];
        yield 'native never contract' => ['class Terminator { public static function stop($error): never { dispatch($error); } }', 'Terminator::stop($error);', 0];
        yield 'PHPDoc alone cannot terminate catch' => ['class Terminator { /** @return never */ public static function stop($error) { report($error); } }', 'Terminator::stop($error);', 1];
        yield 'unknown class binding' => [$exit, 'UnknownTerminator::stop($error);', 1];
        yield 'unknown selected method' => [$exit, 'Terminator::other($error);', 1];
        yield 'conditional declaration is not an unconditional binding' => ['if (enabled()) { '.$exit.' }', 'Terminator::stop($error);', 1];
        yield 'ambiguous alternatives can select a returning declaration' => ['if (enabled()) { '.$exit.' } else { class Terminator { public static function stop($error) {} } }', 'Terminator::stop($error);', 1];
        yield 'open instance receiver can select a returning method' => ['class Terminator { public function stop($error) { exit(1); } } class Returning extends Terminator { public function stop($error) {} }', '$service->stop($error);', 1];
        yield 'dynamic class is not the declared terminal binding' => [$exit, '$class::stop($error);', 1];
        yield 'dynamic method can select another target' => [$exit, 'Terminator::$method($error);', 1];
        yield 'first class callable creation does not terminate catch' => [$exit, 'Terminator::stop(...);', 1];
        yield 'missing required argument is an unsupported invocation' => [$exit, 'Terminator::stop();', 1];
        yield 'generator body does not execute native exit on call' => ['class Terminator { public static function stop($error) { yield $error; exit(1); } }', 'Terminator::stop($error);', 1];
        yield 'native exit after a possible normal return is insufficient' => ['class Terminator { public static function stop($error) { if (recoverable($error)) { return; } exit(1); } }', 'Terminator::stop($error);', 1];
    }

    public function testStandardRunnerAcceptsACompleteNestedFallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-catch-fallback-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function fallback() { try { return load(); } catch (Throwable $error) { try { return recover(); } catch (Throwable $nested) { return []; } } } function swallowed() { try { work(); } catch (Throwable $error) { report($error); } }');

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_catch_all_exceptions')->values();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    public function testStandardRunnerAcceptsAnExplicitExitStatus(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-catch-exit-');
        self::assertIsString($path);
        file_put_contents($path, '<?php function terminate() { try { work(); } catch (Throwable $error) { report($error); exit(1); } } function swallowed() { try { work(); } catch (Throwable $error) { report($error); } }');

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_catch_all_exceptions')->values();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }
}
