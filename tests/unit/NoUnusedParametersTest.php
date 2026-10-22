<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Usage\NoUnusedParameters;
use Cosmira\Soda\Tests\ParsesPhpSnippets;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoUnusedParametersTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('sharedTaskCases')]
    public function testSharedTaskListsPreserveOnlyReadPositions(string $source, array $expected): void
    {
        $root = sys_get_temp_dir().'/soda-task-inputs-'.bin2hex(random_bytes(6));
        mkdir($root);
        $task = $root.'/Tasks.php';
        $control = $root.'/Control.php';
        file_put_contents($task, '<?php namespace TaskProtocol; '.$source);
        file_put_contents($control, '<?php namespace TaskProtocol; class Genuine { private function trailing($kept, $unused) { return $kept; } public function run($value) { return $this->trailing($value, 1); } }');

        try {
            foreach ([[new NoUnusedParameters], RuleCatalog::standard()] as $checks) {
                foreach ([[$task, $control], [$control, $task]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
                    $found = [];
                    $genuine = [];
                    foreach ($result->violations as $violation) {
                        if ($violation->rule !== 'no_unused_parameters') {
                            continue;
                        }
                        if ($violation->class === 'TaskProtocol\\Genuine') {
                            $genuine[] = $violation->toArray();
                        }
                        if (in_array($violation->method, ['install', 'write', 'tail'], true)) {
                            self::assertSame('TaskProtocol\\Tasks', $violation->class);
                            self::assertSame(1, $violation->value);
                            self::assertSame(0, $violation->threshold);
                            preg_match('/\$(\w+)/', $violation->message, $match);
                            $found[] = $violation->method.'::'.$match[1];
                        }
                    }
                    sort($found);
                    self::assertSame($expected, $found);
                    self::assertCount(1, $genuine);
                    self::assertSame('trailing', $genuine[0]['method']);
                    self::assertSame('Parameter $unused is never used by TaskProtocol\\Genuine::trailing. Remove it if the signature is owned here; preserve required contract or positional callback parameters.', $genuine[0]['message']);
                }
            }
        } finally {
            unlink($task);
            unlink($control);
            rmdir($root);
        }
    }

    public static function sharedTaskCases(): iterable
    {
        $methods = 'protected function identify($username, $name) { return $username.$name; } protected function install($username, $name, $trailing = null) { return $name; } protected function write($username, $name) { return $name; }';
        $list = '["identify", "install", "write"]';
        $call = '$this->{$task}($username, $name);';
        $property = 'protected $tasks = '.$list.';';
        $loop = 'foreach ($this->tasks as $task) { '.$call.' }';
        $owned = ['install::trailing', 'install::username', 'write::username'];
        yield 'literal property task list' => ['class Tasks { '.$property.' public function run($username, $name) { '.$loop.' } '.$methods.' }', ['install::trailing']];
        yield 'inline task list' => ['class Tasks { public function run($username, $name) { foreach ('.$list.' as $task) { '.$call.' } } '.$methods.' }', ['install::trailing']];
        yield 'trailing slot remains owned' => ['class Tasks { public function run($username, $name) { foreach (["identify", "tail"] as $task) { '.$call.' } } '.$methods.' protected function tail($username, $unused) { return $username; } }', ['install::trailing', 'install::username', 'tail::unused', 'write::username']];
        yield 'direct fixed calls' => ['class Tasks { public function run($username, $name) { $this->install($username, $name); $this->write($username, $name); } '.$methods.' }', $owned];
        yield 'mutated property list' => ['class Tasks { '.$property.' public function run($username, $name) { $this->tasks = ["identify"]; '.$loop.' } '.$methods.' }', $owned];
        yield 'escaped property list' => ['class Tasks { '.$property.' public function run($username, $name) { change($this->tasks); '.$loop.' } '.$methods.' }', $owned];
        yield 'reference iteration' => ['class Tasks { public function run($username, $name) { foreach ('.$list.' as &$task) { '.$call.' } } '.$methods.' }', $owned];
        yield 'nested callable loop' => ['class Tasks { '.$property.' public function run($username, $name) { return function () use ($username, $name) { '.$loop.' }; } '.$methods.' }', $owned];
        yield 'unknown iterable' => ['class Tasks { public function run($username, $name, $tasks) { foreach ($tasks as $task) { '.$call.' } } '.$methods.' }', $owned];
        yield 'insufficient supplied slots' => ['class Tasks { public function run($username, $name) { foreach ('.$list.' as $task) { $this->{$task}($username); } } '.$methods.' }', $owned];
        yield 'unpacked arguments' => ['class Tasks { public function run($username, $name) { foreach ('.$list.' as $task) { $this->{$task}(...[$username, $name]); } } '.$methods.' }', $owned];
        yield 'named arguments' => ['class Tasks { public function run($username, $name) { foreach ('.$list.' as $task) { $this->{$task}(username: $username, name: $name); } } '.$methods.' }', $owned];
        yield 'first class task reference' => ['class Tasks { public function run($username, $name) { foreach ('.$list.' as $task) { $this->{$task}(...); } } '.$methods.' }', $owned];
        yield 'array element mutation' => ['class Tasks { '.$property.' public function run($username, $name) { $this->tasks[0] = "write"; '.$loop.' } '.$methods.' }', $owned];
        yield 'receiver alias mutation' => ['class Tasks { '.$property.' public function run($username, $name) { $alias = $this; $alias->tasks = ["identify"]; '.$loop.' } '.$methods.' }', $owned];
        yield 'reference escaped receiver' => ['class Tasks { '.$property.' public function run($username, $name) { change($this); '.$loop.' } '.$methods.' }', $owned];
        yield 'trait caller with selected property' => ['trait Dispatch { '.$property.' public function run($username, $name) { '.$loop.' } } class Tasks { use Dispatch; '.$methods.' }', ['install::trailing']];
        yield 'inherited caller with selected property' => ['class Dispatch { '.$property.' public function run($username, $name) { '.$loop.' } } class Tasks extends Dispatch { '.$methods.' }', ['install::trailing']];
        yield 'inherited property shadow' => ['class Dispatch { '.$property.' public function run($username, $name) { '.$loop.' } } class Tasks extends Dispatch { protected $tasks = ["identify"]; '.$methods.' }', $owned];
        yield 'private lexical target is not a child method' => ['class Dispatch { public function run($username, $name) { foreach (["identify", "install"] as $task) { '.$call.' } } protected function identify($username, $name) { return $username.$name; } private function install($username, $name) { return $username.$name; } } class Tasks extends Dispatch { public function install($username, $name) { return $name; } }', ['install::username']];
        yield 'duplicate conditional owner' => ['if (condition()) { class Tasks { '.$property.' public function run($username, $name) { '.$loop.' } '.$methods.' } } else { class Tasks { protected $tasks = ["identify"]; public function run($username, $name) { '.$loop.' } '.$methods.' } }', ['install::trailing', 'install::trailing', 'install::username', 'install::username', 'write::username', 'write::username']];
        yield 'unread gap is not a protected prefix' => ['class Tasks { public function run($dead, $username, $name) { foreach (["identify", "install"] as $task) { $this->$task($dead, $username, $name); } } protected function identify($dead, $username, $name) { return $username.$name; } protected function install($dead, $unused, $name) { return $name; } }', ['install::dead', 'install::unused']];
        yield 'duplicate argument values' => ['class Tasks { public function run($value) { foreach (["identify", "install"] as $task) { $this->$task($value, $value); } } protected function identify($username, $name) { return $username.$name; } protected function install($username, $name) { return $name; } }', ['install::username']];
        yield 'known argument alias' => ['class Tasks { public function run($username) { $name = $username; foreach (["identify", "install"] as $task) { '.$call.' } } protected function identify($username, $name) { return $username.$name; } protected function install($username, $name) { return $name; } }', ['install::username']];
        yield 'duplicate literal keys' => ['class Tasks { public function run($username, $name) { foreach (["same" => "identify", "same" => "install", "write"] as $task) { '.$call.' } } '.$methods.' }', $owned];
        yield 'missing required target argument' => ['class Tasks { public function run($username, $name) { foreach ('.$list.' as $task) { '.$call.' } } '.str_replace('$trailing = null', '$trailing', $methods).' }', $owned];
        yield 'unreachable loop after return' => ['class Tasks { public function run($username, $name) { return; foreach ('.$list.' as $task) { '.$call.' } } '.$methods.' }', $owned];
        yield 'unreachable loop after throw' => ['class Tasks { public function run($username, $name) { throw new \\RuntimeException; foreach ('.$list.' as $task) { '.$call.' } } '.$methods.' }', $owned];
        yield 'unreachable loop after exit' => ['class Tasks { public function run($username, $name) { exit; foreach ('.$list.' as $task) { '.$call.' } } '.$methods.' }', $owned];
        yield 'constant false branch' => ['class Tasks { public function run($username, $name) { if (false) { foreach ('.$list.' as $task) { '.$call.' } } } '.$methods.' }', $owned];
        yield 'constant false loop' => ['class Tasks { public function run($username, $name) { while (false) { foreach ('.$list.' as $task) { '.$call.' } } } '.$methods.' }', $owned];
        foreach (['consume(exit(1));', 'consume(throw new \\RuntimeException);', '(exit(1))->notify();', '(throw new \\RuntimeException)->notify();', 'if (condition(exit(1))) {}', 'if (condition(throw new \\RuntimeException)) {}', '(function () { exit; })();', '(fn () => exit)();'] as $prefix) {
            yield 'eager terminal prefix '.$prefix => ['class Tasks { public function run($username, $name) { '.$prefix.' foreach ('.$list.' as $task) { '.$call.' } } '.$methods.' }', $owned];
        }
        foreach (['function () { exit; }', 'fn () => exit'] as $deferred) {
            yield 'deferred terminal argument '.$deferred => ['function consume($callback) { return $callback; } class Tasks { public function run($username, $name) { consume('.$deferred.'); foreach ('.$list.' as $task) { '.$call.' } } '.$methods.' }', ['install::trailing']];
        }
        yield 'variable table exposes receiver' => ['class Tasks { '.$property.' public function rewrite() { $scope = compact("this"); $scope["this"]->tasks = ["identify"]; } public function run($username, $name) { $this->rewrite(); '.$loop.' } '.$methods.' }', $owned];
        yield 'closure receiver alias mutation' => ['class Tasks { '.$property.' public function rewrite() { $callback = function () { $owner = $this; $owner->tasks = ["identify"]; }; $callback(); } public function run($username, $name) { $this->rewrite(); '.$loop.' } '.$methods.' }', $owned];
        yield 'foreign anonymous receiver alias' => ['class Tasks { '.$property.' public function unrelated() { return new class { public function rewrite() { $owner = $this; $owner->tasks = []; } }; } public function run($username, $name) { $this->unrelated(); '.$loop.' } '.$methods.' }', ['install::trailing']];
        yield 'returned receiver mutation' => ['class Tasks { '.$property.' public function identity() { return $this; } public function rewrite() { $this->identity()->tasks = ["identify"]; } public function run($username, $name) { $this->rewrite(); '.$loop.' } '.$methods.' }', $owned];
        yield 'reference iterable mutation' => ['class Tasks { '.$property.' public function rewrite() { foreach ($this->tasks as &$task) { $task = "identify"; } } public function run($username, $name) { $this->rewrite(); '.$loop.' } '.$methods.' }', $owned];

    }

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

    #[DataProvider('abstractTraitRequirements')]
    public function testAbstractTraitRequirementsAcrossFiles(string $traits, string $declaration, string $method = 'compile', int $requiredCount = 1): void
    {
        $root = sys_get_temp_dir().'/soda-abstract-trait-'.bin2hex(random_bytes(6));
        mkdir($root);
        $contract = $root.'/Contract.php';
        $implementation = $root.'/Implementation.php';
        file_put_contents($contract, '<?php namespace App; '.$traits.' trait Concrete { public function compile($query, $column = null, $aggregate = null) { return $query; } }');
        file_put_contents($implementation, '<?php namespace App; '.$declaration.' class Owned { use Concrete; public function compile($query, $column = null, $aggregate = null, $extra = null) { return $query; } } class Unrelated { public function compile($unused) {} }');

        try {
            foreach ([[new NoUnusedParameters], RuleCatalog::standard()] as $checks) {
                foreach ([[$contract, $implementation], [$implementation, $contract]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                    $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_unused_parameters' && in_array($row->class, ['App\\Contracted', 'App\\Owned', 'App\\Unrelated'], true))->values();
                    self::assertCount($requiredCount + 4, $findings);
                    $required = $findings->filter(static fn ($row): bool => $row->class === 'App\\Contracted')->values();
                    self::assertCount($requiredCount, $required);
                    $inputs = $requiredCount === 1 ? ['extra'] : ['column', 'aggregate', 'extra'];
                    foreach ($inputs as $position => $input) {
                        self::assertSame($method, $required[$position]->method);
                        self::assertStringContainsString('$'.$input, $required[$position]->message);
                    }
                    self::assertCount(3, $findings->filter(static fn ($row): bool => $row->class === 'App\\Owned'));
                    self::assertCount(1, $findings->filter(static fn ($row): bool => $row->class === 'App\\Unrelated'));
                }
            }
        } finally {
            unlink($contract);
            unlink($implementation);
            rmdir($root);
        }
    }

    public static function abstractTraitRequirements(): iterable
    {
        $signature = 'abstract public function compile($query, $column = null, $aggregate = null);';
        $implementation = 'public function compile($query, $column = null, $aggregate = null, $extra = null) { return $query; }';
        yield 'direct requirement' => ['trait Required { '.$signature.' }', 'class Contracted { use Required; '.$implementation.' }'];
        yield 'nested requirement' => ['trait Required { '.$signature.' } trait Nested { use Required; }', 'class Contracted { use Nested; '.$implementation.' }'];
        yield 'case insensitive method' => ['trait Required { '.str_replace('compile', 'COMPILE', $signature).' }', 'class Contracted { use Required; '.$implementation.' }'];
        yield 'abstract constructor requirement' => ['trait Required { '.str_replace('compile', '__construct', $signature).' }', 'class Contracted { use Required; '.str_replace(['compile', 'return $query;'], ['__construct', 'echo $query;'], $implementation).' }', '__construct'];
        yield 'private abstract requirement' => ['trait Required { '.str_replace('public', 'private', $signature).' }', 'class Contracted { use Required; '.$implementation.' }'];
        yield 'visibility adaptation retains requirement' => ['trait Required { '.$signature.' }', 'class Contracted { use Required { compile as private; } '.str_replace('public', 'private', $implementation).' }'];
        yield 'abstract alias adds its own requirement' => ['trait Required { '.$signature.' }', 'class Contracted { use Required { compile as execute; } public function compile($query, $column = null, $aggregate = null) { return [$query, $column, $aggregate]; } '.str_replace('compile', 'execute', $implementation).' }', 'execute'];
        yield 'insteadof cannot remove abstract obligation' => ['trait Required { '.$signature.' } trait Selected { public function compile($query, $column = null, $aggregate = null) { return $query; } }', 'class Contracted { use Required, Selected { Selected::compile insteadof Required; } '.$implementation.' }'];
        yield 'intermediate concrete implementation owns later overrides' => ['trait Required { '.$signature.' } trait Nested { use Required; public function compile($query, $column = null, $aggregate = null) { return $query; } }', 'class Contracted { use Nested; '.$implementation.' }', 'compile', 3];
        yield 'concrete alias does not acquire abstract requirement' => ['trait Required { '.$signature.' } trait Selected { public function compile($query, $column = null, $aggregate = null) { return $query; } }', 'class Contracted { use Required, Selected { Selected::compile insteadof Required; Selected::compile as execute; } '.str_replace('compile', 'execute', $implementation).' }', 'execute', 3];
        yield 'qualified abstract alias across separate use groups' => ['trait Required { '.$signature.' } trait Other { public function compile($query, $column = null, $aggregate = null) { return $query; } }', 'class Contracted { use Required { Required::compile as execute; } use Other; public function compile($query, $column = null, $aggregate = null) { return [$query, $column, $aggregate]; } '.str_replace('compile', 'execute', $implementation).' }', 'execute'];
        yield 'qualified concrete alias across separate use groups' => ['trait Required { '.$signature.' } trait Other { public function compile($query, $column = null, $aggregate = null) { return $query; } }', 'class Contracted { use Other { Other::compile as execute; } use Required; public function compile($query, $column = null, $aggregate = null) { return [$query, $column, $aggregate]; } '.str_replace('compile', 'execute', $implementation).' }', 'execute', 3];
    }

    #[DataProvider('composedAbstractTraitRequirements')]
    public function testAbstractTraitRequirementsFollowSelectedBodies(string $traits, string $classes, array $expected): void
    {
        $root = sys_get_temp_dir().'/soda-trait-selection-'.bin2hex(random_bytes(6));
        mkdir($root);
        $contract = $root.'/Contract.php';
        $implementation = $root.'/Implementation.php';
        file_put_contents($contract, '<?php namespace App; '.$traits);
        file_put_contents($implementation, '<?php namespace App; '.$classes);

        try {
            foreach ([[new NoUnusedParameters], RuleCatalog::standard()] as $checks) {
                foreach ([[$contract, $implementation], [$implementation, $contract]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                    $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_unused_parameters')->values();
                    self::assertCount(count($expected), $findings);
                    foreach ($expected as $entry) {
                        [$class, $input] = $entry;
                        $method = $entry[2] ?? 'run';
                        $matches = $findings->filter(static fn ($row): bool => $row->class === 'App\\'.$class && $row->method === $method && str_contains($row->message, 'Parameter $'.$input.' '))->values();
                        self::assertCount(1, $matches);
                        self::assertSame(1, $matches[0]->line);
                        self::assertSame(1, $matches[0]->value);
                        self::assertSame(0, $matches[0]->threshold);
                    }
                }
            }
        } finally {
            unlink($contract);
            unlink($implementation);
            rmdir($root);
        }
    }

    public static function composedAbstractTraitRequirements(): iterable
    {
        $required = 'trait Required { abstract public function run($first, $second = null); }';
        $body = 'trait Body { public function run($first, $second = null) {} }';
        yield 'selected sibling concrete body satisfies requirement' => [$required.$body, 'class Consumer { use Required, Body; }', []];
        yield 'concrete body fulfills earlier abstract use group' => [$required.$body, 'class Consumer { use Required; use Body; }', []];
        yield 'concrete body fulfills later abstract use group' => [$required.$body, 'class Consumer { use Body; use Required; }', []];
        yield 'concrete body without requirement owns inputs' => [$body, 'class Consumer { use Body; }', [['Body', 'first'], ['Body', 'second']]];
        yield 'replaced sibling body receives no requirement' => [$required.$body, 'class Consumer { use Required, Body; public function run($first, $second = null) {} }', [['Body', 'first'], ['Body', 'second']]];
        yield 'excluded concrete body cannot discharge nested requirement' => [$required.$body.' trait Nested { use Required, Body { Required::run insteadof Body; } }', 'class Consumer { use Nested; public function run($first, $second = null) {} }', [['Body', 'first'], ['Body', 'second']]];
        yield 'private parent implementation discharges obligation' => ['trait Required { abstract private function run($first, $second = null); }', 'class Base { use Required; private function run($first, $second = null) {} } class Child extends Base { private function run($owned) {} }', [['Child', 'owned']]];
        yield 'child abstract requirement does not constrain private parent body' => [$required, 'class Base { private function run($first, $second = null) {} } abstract class Child extends Base { use Required; }', [['Base', 'first'], ['Base', 'second']]];
        yield 'inherited abstract alias preserves only declared positions' => ['trait Required { abstract public function compile($first, $second = null, $third = null); }', 'abstract class Base { use Required { compile as run; } public function compile($first, $second = null, $third = null) {} } class Child extends Base { public function run($first, $second = null, $third = null, $extra = null) {} }', [['Child', 'extra']]];
        yield 'private renamed parent alias keeps child inputs owned' => ['trait Body { public function compile($first, $second = null) {} }', 'class Base { use Body { compile as private run; } } class Child extends Base { public function run($first, $second = null) {} }', [['Child', 'first'], ['Child', 'second']]];
        yield 'private visibility adaptation keeps child inputs owned' => [$body, 'class Base { use Body { run as private; } } class Child extends Base { public function run($first, $second = null) {} }', [['Body', 'first'], ['Body', 'second'], ['Child', 'first'], ['Child', 'second']]];
        yield 'public alias of private method preserves only inherited positions' => ['trait Body { private function compile($first, $second = null) {} }', 'class Base { use Body { compile as public run; } } class Child extends Base { public function run($first, $second = null, $extra = null) {} }', [['Body', 'first', 'compile'], ['Body', 'second', 'compile'], ['Child', 'extra']]];
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

    #[DataProvider('collaboratorCases')]
    public function testCollaboratorDispatchFollowsOpenNativeFields(string $caller, int $expected, int $expectedCaller = 0): void
    {
        $root = sys_get_temp_dir().'/soda-collaborator-inputs-'.bin2hex(random_bytes(6));
        mkdir($root);
        $targets = $root.'/Targets.php';
        $dispatch = $root.'/Dispatch.php';
        file_put_contents($targets, '<?php namespace App; abstract class Grammar {} class Known extends Grammar { public function compileSkip($input) { return $input; } } class Quiet extends Grammar { public function compileSkip($unused, $extra = null) {} } class Unrelated { public function compileSkip($unused) {} }');
        file_put_contents($dispatch, '<?php namespace App; '.$caller);

        try {
            foreach ([[$targets, $dispatch], [$dispatch, $targets]] as $paths) {
                $result = (new Runner)->check($paths, RuleCatalog::standard());
                self::assertSame([], $result->violations->filter(static fn ($violation): bool => $violation->rule === 'parse_error')->all());
                $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters' && in_array($violation->class, ['App\\Quiet', 'App\\Unrelated'], true))->values();
                self::assertCount($expected, $findings);
                self::assertCount(1, $findings->filter(static fn ($violation): bool => str_contains($violation->message, '$extra')));
                self::assertCount(1, $findings->filter(static fn ($violation): bool => $violation->class === 'App\\Unrelated'));
                self::assertCount($expectedCaller, $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_unused_parameters' && $violation->class === 'App\\Blueprint' && $violation->method === 'compileSkip'));
            }
        } finally {
            unlink($targets);
            unlink($dispatch);
            rmdir($root);
        }
    }

    public static function collaboratorCases(): iterable
    {
        $field = 'private Grammar $grammar;';
        $constructor = 'public function __construct(Grammar $grammar) { $this->grammar = $grammar; }';
        $call = '$method = "compile".format($kind); return $this->grammar->$method($input);';
        $run = 'public function run($kind, $input) { '.$call.' }';
        yield 'native constructor field' => ['class Blueprint { '.$field.$constructor.$run.' }', 2];
        yield 'promoted constructor field' => ['class Blueprint { public function __construct(private Grammar $grammar) {} '.$run.' }', 2];
        yield 'getter backed by public native setter' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; return $this; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar = $source->grammar(); } '.$run.' }', 2];
        yield 'typed backing field accepts native setter family' => ['class Source { protected Grammar $grammar; public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 2];
        yield 'initialized readonly backing field rejects later setter' => ['class Source { protected readonly Grammar $grammar; public function __construct() { $this->grammar=new Known; } public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 3];
        yield 'readonly class backing field rejects later setter' => ['readonly class Source { protected Grammar $grammar; public function __construct() { $this->grammar=new Known; } public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 3];
        yield 'readonly backing constructor accepts open input' => ['class Source { protected readonly Grammar $grammar; public function __construct(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 2];
        yield 'trait readonly backing field rejects later setter' => ['trait Stored { protected readonly Grammar $grammar; public function __construct() { $this->grammar=new Known; } public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Source { use Stored; } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 3];
        yield 'inherited readonly backing field rejects later setter' => ['class Stored { protected readonly Grammar $grammar; public function __construct() { $this->grammar=new Known; } public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Source extends Stored {} class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 3];
        yield 'readonly class constructor accepts open input' => ['readonly class Source { protected Grammar $grammar; public function __construct(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 2];
        yield 'promoted readonly backing constructor accepts open input' => ['class Source { public function __construct(private readonly Grammar $grammar) {} public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 2];
        yield 'promoted readonly backing constructor cannot initialize twice' => ['class Source { public function __construct(private readonly Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar=$source->grammar(); } '.$run.' }', 3];
        yield 'finite constructor' => ['class Blueprint { '.$field.' public function __construct() { $this->grammar = new Known; } '.$run.' }', 3];
        yield 'finite getter' => ['class Source { public function grammar(): Grammar { return new Known; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar = $source->grammar(); } '.$run.' }', 3];
        yield 'private finite constructor' => ['class Blueprint { '.$field.' private function __construct(Grammar $grammar) { $this->grammar = $grammar; } public static function create() { return new self(new Known); } '.$run.' }', 3];
        yield 'property overwrite' => ['class Blueprint { '.$field.$constructor.' public function run($kind, $input) { $this->grammar = new Known; '.$call.' } }', 3];
        yield 'untyped field' => ['class Blueprint { private $grammar; '.$constructor.$run.' }', 3];
        yield 'wrong prefix' => ['class Blueprint { '.$field.$constructor.' public function run($kind, $input) { $method = "other".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'named invocation' => ['class Blueprint { '.$field.$constructor.' public function run($kind, $input) { $method = "compile".format($kind); return $this->grammar->$method(unused: $input); } }', 3];
        yield 'unpacked invocation' => ['class Blueprint { '.$field.$constructor.' public function run($kind, $input) { $method = "compile".format($kind); return $this->grammar->$method(...[$input]); } }', 3];
        yield 'first-class invocation' => ['class Blueprint { '.$field.$constructor.' public function run($kind, $input) { $method = "compile".format($kind); return $this->grammar->$method(...); } }', 3];
        yield 'prior stored-object alias mutation closes getter state' => ['final class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar=$grammar; } public function clear() { $this->grammar=new Known; return 1; } public function grammar() { return $this->grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->source=$source; $this->nonce=$this->source->clear();$this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'narrow setter cannot donate broader siblings' => ['class Source { protected $grammar; public function store(Known $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'narrow constructor cannot donate broader siblings' => ['class Blueprint { private Grammar $grammar; public function __construct(Known $grammar) { $this->grammar=$grammar; } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'collaborator protocol does not donate own caller method' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } public function compileSkip($unused) {} }', 2, 1];
        yield 'private inherited field is not readable in child caller' => ['class Stored { private Grammar $grammar; public function __construct(Grammar $grammar) { $this->grammar=$grammar; } } class Blueprint extends Stored { public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'child getter cannot read parent private setter slot' => ['class Stored { private $grammar; public function store(Grammar $grammar) { $this->grammar=$grammar; } } class Source extends Stored { public function grammar() { return $this->grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'child setter does not write inherited getter private slot' => ['class Stored { private $grammar; public function grammar() { return $this->grammar; } } class Source extends Stored { public function store(Grammar $grammar) { $this->grammar=$grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'required getter input cannot be omitted' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar($required) { return $this->grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'inherited caller retains original private field scope' => ['class Stored { private Grammar $grammar; public function __construct(Grammar $grammar) { $this->grammar=$grammar; } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } } class Blueprint extends Stored {}', 2];
        yield 'inherited getter and setter share parent private storage' => ['class Stored { private $grammar; public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Source extends Stored {}class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 2];
        yield 'trait private backing field shares consuming scope' => ['trait Stored { private $grammar; public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } } class Source { use Stored; }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 2];
        yield 'trait private collaborator field shares consuming scope' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->grammar; } }trait Stored { private Grammar $grammar; } class Blueprint { use Stored; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 2];
        yield 'public setter still permits injection after finite construction' => ['class Source { protected $grammar; public function __construct() { $this->grammar=new Known; } public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 2];
        yield 'native prefix field narrows dependency to finite override' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->grammar; } }final class FiniteSource extends Source { public function grammar() { return new Known; } } class Blueprint { private FiniteSource $source; private Grammar $grammar; public function __construct(Source $source) { $this->source=$source; $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'matching native prefix field preserves dependency family' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->grammar; } }class Blueprint { private Source $source; private Grammar $grammar; public function __construct(Source $source) { $this->source=$source; $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 2];
        yield 'union prefix field narrows dependency to finite overrides' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->grammar; } }final class FiniteSource extends Source { public function grammar() { return new Known; } } final class OtherFiniteSource extends Source { public function grammar() { return new Known; } } class Blueprint { private FiniteSource|OtherFiniteSource $source; private Grammar $grammar; public function __construct(Source $source) { $this->source=$source; $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'duplicate prefix field cannot hide earlier narrowing' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->grammar; } }final class FiniteSource extends Source { public function grammar() { return new Known; } } class Blueprint { private FiniteSource $source; private Grammar $grammar; public function __construct(Source $source, FiniteSource $other) { $this->source=$source; $this->source=$other; $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        yield 'typed union backing field narrows public setter input' => ['class OtherKnown extends Grammar {} class Source { protected Known|OtherKnown $grammar; public function store(Grammar $grammar) { $this->grammar=$grammar; } public function grammar() { return $this->grammar; } }class Blueprint { protected Grammar $grammar; public function __construct(Source $source) { $this->grammar=$source->grammar(); } public function run($kind, $input) { $method="compile".format($kind); return $this->grammar->$method($input); } }', 3];
        $normal = 'if (method_exists($this->grammar, $method) || unknownRhs($method)) { return $this->grammar->$method($input); }';
        $guardCases = [
            'existing field contract without OR'             => ['return $this->grammar->$method($input);', false, '', 2],
            'native exact receiver and selector'             => [$normal, true],
            'explicit global native exact guard'             => [str_replace('method_exists(', '\\method_exists(', $normal), true],
            'unknown RHS can throw or rebind but is skipped' => [str_replace('unknownRhs($method)', 'unknownRhs($method, function () use (&$method) { $method = "outside"; })', $normal), true],
            'different receiver'                             => [str_replace('method_exists($this->grammar,', 'method_exists($other,', $normal), false],
            'different selector'                             => [str_replace('method_exists($this->grammar, $method)', 'method_exists($this->grammar, $other)', $normal), false],
            'false branch invocation'                        => ['if (method_exists($this->grammar, $method) || unknownRhs($method)) {} else { return $this->grammar->$method($input); }', false],
            'escape before if'                               => ['unknownBefore($method); '.$normal, false],
            'escape inside then before invocation'           => [str_replace('{ return', '{ unknownBefore($method); return', $normal), false],
            'escape after invocation remains unsupported'    => [str_replace('return $this->grammar->$method($input);', '$result = $this->grammar->$method($input); unknownAfter($method); return $result;', $normal), false],
            'shadowed unqualified guard'                     => [$normal, false, 'function method_exists($object, &$method) { $method="outside"; return false; }'],
            'explicit custom guard'                          => [str_replace('method_exists(', '\\App\\method_exists(', $normal), false, 'function method_exists($object, &$method) { $method="outside"; return false; }'],
            'negated native guard'                           => [str_replace('if (method_exists(', 'if (! method_exists(', $normal), false],
            'AND requires RHS evaluation'                    => [str_replace(' || ', ' && ', $normal), false],
            'fallback is first operand'                      => ['if (unknownRhs($method) || method_exists($this->grammar, $method)) { return $this->grammar->$method($input); }', false],
            'named native guard arguments unsupported'       => [str_replace('method_exists($this->grammar, $method)', 'method_exists(object_or_class: $this->grammar, method: $method)', $normal), false],
            'unpacked native guard arguments unsupported'    => [str_replace('method_exists($this->grammar, $method)', 'method_exists(...[$this->grammar, $method])', $normal), false],
            'receiver overwritten before guard'              => ['$this->grammar = new Known; '.$normal, false],
        ];
        foreach ($guardCases as $name => $case) {
            [$body, $qualifies] = $case;
            $caller = ($case[2] ?? '').' class Blueprint { '.$field.$constructor.' public function run($kind, $input, $other) { $method="compile".format($kind); '.$body.' } }';
            yield 'short-circuit '.$name => [$caller, $case[3] ?? ($qualifies ? 2 : 3)];
        }

        yield 'source input overwritten' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $source = new Source; $this->grammar = $source->grammar(); } '.$run.' }', 3];
        yield 'setter input overwritten' => ['class Source { protected $grammar; public function store(Grammar $grammar) { $grammar = new Known; $this->grammar = $grammar; } public function grammar() { return $this->grammar; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar = $source->grammar(); } '.$run.' }', 3];
        yield 'getter returns another field' => ['class Source { protected $grammar; protected $other; public function store(Grammar $grammar) { $this->grammar = $grammar; } public function grammar() { return $this->other; } } class Blueprint { '.$field.' public function __construct(Source $source) { $this->grammar = $source->grammar(); } '.$run.' }', 3];
    }

    #[DataProvider('nativeSelectorCases')]
    public function testNativeComputedDispatchPreservesOnlyInheritedPositions(string $body): void
    {
        $root = sys_get_temp_dir().'/soda-native-inputs-'.bin2hex(random_bytes(6));
        mkdir($root);
        $dispatch = $root.'/Grammar.php';
        $steps = $root.'/UuidGrammar.php';
        file_put_contents($dispatch, '<?php namespace App; abstract class Grammar { protected function getType($column) { '.$body.' } }');
        file_put_contents($steps, '<?php namespace App; class UuidGrammar extends Grammar { protected function typeUuid($unused, $extra = null) {} protected function other($unrelated) {} } class Standalone { protected function typeUuid($unrelated) {} }');

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

    public static function nativeSelectorCases(): iterable
    {
        yield 'direct concatenation' => ["return \$this->{'type'.ucfirst(\$column->type)}(\$column);"];
        yield 'opaque formatter' => ["return \$this->{'type'.unknownFormatter(\$column->type)}(\$column);"];
        yield 'direct interpolation' => ['return $this->{"type{$column->type}"}($column);'];
        yield 'concatenated binding' => ["\$method = 'type'.ucfirst(\$column->type); return \$this->\$method(\$column);"];
        yield 'guarded concatenated binding' => ["if (method_exists(\$this, \$method = 'type'.ucfirst(\$column->type))) { return \$this->\$method(\$column); }"];
        yield 'nested statement-list binding' => ["foreach (\$column->types as \$type) { if (isset(\$column->\$type)) { \$method = 'type'.ucfirst(\$type); return \$this->\$method(\$column); } }"];
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
        yield 'direct concatenation' => ["return \$this->{'render'.format(\$kind)}(\$input);", 1];
        yield 'direct wrong prefix' => ["return \$this->{'other'.format(\$kind)}(\$input);", 2];
        yield 'direct named invocation' => ["return \$this->{'render'.format(\$kind)}(unused: \$input);", 2];
        yield 'direct unpacked invocation' => ["return \$this->{'render'.format(\$kind)}(...[\$input]);", 2];
        yield 'first-class reference cannot form string suffix' => ["return \$this->{'render'.suffix(...)}(\$input);", 2];
        yield 'direct first-class reference' => ["return \$this->{'render'.format(\$kind)}(...);", 2];
        yield 'direct foreign receiver' => ["return \$other->{'render'.format(\$kind)}(\$input);", 2];
        yield 'direct nested invocation' => ["return function () use (\$kind, \$input) { return \$this->{'render'.format(\$kind)}(\$input); };", 2];
        yield 'closed concatenation' => ["return \$this->{'render'.'Other'}(\$input);", 2];
        yield 'closed numeric concatenation' => ["return \$this->{'render'. 1}(\$input);", 2];
        yield 'closed named constant input' => ['$kind = FIXED_KIND; return $this->{"render".$kind}($input);', 2];
        yield 'closed class constant input' => ['$kind = Options::FIXED_KIND; return $this->{"render".$kind}($input);', 2];

        yield 'closed concatenation input' => ["\$kind = 'Other'; return \$this->{'render'.\$kind}(\$input);", 2];
        yield 'closed concatenation alias' => ["\$suffix = 'Other'; \$kind = \$suffix; return \$this->{'render'.\$kind}(\$input);", 2];
        yield 'closed cast suffix' => ["return \$this->{'render'.(string)'Other'}(\$input);", 2];
        yield 'closed native choice' => ["return \$this->{'render'.(\$kind ? 'Other' : 'Also')}(\$input);", 2];
        yield 'goto bypasses binding' => ['goto invoke; '.$binding.' invoke: '.$call, 2];
        yield 'conditional literal keeps incoming selection open' => ["if (condition()) { \$kind = 'Other'; } return \$this->{'render'.\$kind}(\$input);", 1];
        yield 'later conditional write keeps selection open' => ["\$kind = 'Other'; if (condition()) { \$kind = \$other; } return \$this->{'render'.\$kind}(\$input);", 1];
        yield 'closed cast through binding' => ["\$kind = (string)'Other'; return \$this->{'render'.\$kind}(\$input);", 2];
        yield 'closed choice through binding' => ["\$kind = \$other ? 'Other' : 'Also'; return \$this->{'render'.\$kind}(\$input);", 2];
        yield 'closed native suffix binding' => ["\$kind = 'Al'.'so'; return \$this->{'render'.\$kind}(\$input);", 2];
        yield 'closed inline assignment' => ["return \$this->{'render'.(\$kind = 'Other')}(\$input);", 2];
        yield 'same-block assignment' => ['if (condition()) { '.$binding.$call.' }', 1];
        yield 'assignment dominates nested later statement' => ['if (condition()) { '.$binding.' if (other()) { '.$call.' } }', 1];
        yield 'sibling branches do not dominate' => ['if (condition()) { '.$binding.' } else { '.$call.' }', 2];
        yield 'nested block overwrite' => ['if (condition()) { '.$binding.' $method = $kind; '.$call.' }', 2];
        yield 'block assignment after invocation' => ['if (condition()) { '.$call.$binding.' }', 2];
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

    #[DataProvider('pipelineRegressions')]
    public function testPipelinePreservesValidCodeAndDetectsControl(string $valid, string $invalid): void
    {
        $this->assertRulePipeline('no_unused_parameters', $valid, $invalid);
    }

    public static function pipelineRegressions(): iterable
    {
        yield 'Prompts: parameters forwarded by variable snapshot' => [
            'function prompt(string $label, string $hint): array { return get_defined_vars(); }',
            'function prompt(string $label, string $hint): array { return []; }',
        ];
        yield 'Sanctum: third positional callback argument' => [
            'register(function ($app, $name, array $config) { return $config; });',
            'register(function ($app, $name, array $config, $unused) { return $config; });',
        ];
        yield 'Pail and Sail: second positional callback argument' => [
            'run(fn (string $type, string $buffer) => trim($buffer));',
            'run(fn (string $type, string $buffer, string $unused) => trim($buffer));',
        ];
        yield 'Serializable Closure: native stream registration' => [
            'class Stream { public function stream_open($path, $mode, $options, &$opened) {} public static function register() { stream_wrapper_register("test", __CLASS__); } }',
            'class Stream { public function stream_open($path, $mode, $options, &$opened) {} }',
        ];
    }
}
