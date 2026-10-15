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
