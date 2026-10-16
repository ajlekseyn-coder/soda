<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Rules\Architecture\NoModeParameters;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoModeParametersTest extends TestCase
{
    #[DataProvider('cases')]
    public function testSelectorsAndDataBoundaries(string $body, int $expected): void
    {
        $source = '<?php function execute($mode) { '.$body.' }';
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $findings = iterator_to_array((new NoModeParameters)->checkFile(new FileFacts('/project/modes.php', $source, $nodes, [])));
        self::assertCount($expected, $findings);
        if ($expected !== 0) {
            self::assertSame(1, $findings[0]->line);
            self::assertSame(1, $findings[0]->value);
            self::assertSame(0, $findings[0]->threshold);
            self::assertStringContainsString('$mode', $findings[0]->message);
        }
    }

    public function testStandardRunnerKeepsValidationSeparateFromWorkingOperations(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-mode-validation-');
        self::assertIsString($path);
        file_put_contents($path, <<<'PHP'
<?php
function router(string $protocol): mixed
{
    return match ($protocol) {
        'pusher' => makeRouter(),
        default => throw new InvalidArgumentException("Unsupported protocol [{$protocol}]."),
    };
}
function execute(string $mode): mixed
{
    return match ($mode) {
        'new' => create(),
        'old' => update(),
        default => throw new InvalidArgumentException(),
    };
}
PHP);

        try {
            $result = (new Runner)->check([$path], RuleCatalog::standard());
            $findings = $result->violations->filter(static fn ($violation): bool => $violation->rule === 'no_mode_parameters')->values();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
            self::assertSame(11, $findings[0]->line);
            self::assertStringContainsString('$mode', $findings[0]->message);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('continuingBranchOrigins')]
    public function testOnlyContinuingBranchOriginsSelectOperations(string $body, int $expected): void
    {
        $root = sys_get_temp_dir().'/soda-mode-origins-'.bin2hex(random_bytes(6));
        mkdir($root);
        $target = $root.'/Target.php';
        $control = $root.'/Control.php';
        file_put_contents($target, '<?php function target($mode, $normalize = null) { '.$body.' }');
        file_put_contents($control, '<?php function genuine($mode) { return match ($mode) { 0 => create(), 1 => update() }; }');

        try {
            foreach ([[new NoModeParameters], RuleCatalog::standard()] as $checks) {
                foreach ([[$target, $control], [$control, $target]] as $paths) {
                    $result = (new Runner)->check($paths, $checks);
                    self::assertSame([], $result->violations->filter(static fn ($row): bool => $row->rule === 'parse_error')->all());
                    $findings = $result->violations->filter(static fn ($row): bool => $row->rule === 'no_mode_parameters')->values();
                    $genuine = $findings->filter(static fn ($row): bool => $row->file === $control)->values();
                    self::assertCount(1, $genuine);
                    self::assertSame(1, $genuine[0]->line);
                    self::assertSame(1, $genuine[0]->value);
                    self::assertSame(0, $genuine[0]->threshold);
                    self::assertSame('Parameter $mode selects distinct operations at lines 1. Expose separate named operations instead of a mode selector.', $genuine[0]->message);
                    $targets = $findings->filter(static fn ($row): bool => $row->file === $target)->values();
                    self::assertCount($expected, $targets);
                    self::assertCount($expected + 1, $findings);
                    if ($expected !== 0) {
                        self::assertSame(1, $targets[0]->line);
                        self::assertSame(1, $targets[0]->value);
                        self::assertSame(0, $targets[0]->threshold);
                        self::assertStringContainsString('Parameter $mode selects distinct operations', $targets[0]->message);
                    }
                }
            }
        } finally {
            unlink($target);
            unlink($control);
            rmdir($root);
        }
    }

    public static function continuingBranchOrigins(): iterable
    {
        $dispatch = 'return match ($mode) { 0 => create(), 1 => update() };';
        $aliasDispatch = 'return match ($selected) { 0 => create(), 1 => update() };';

        yield 'both branches overwrite input' => ['if (choose()) { $mode = 0; } else { $mode = 0; } '.$dispatch, 0];
        yield 'variable condition with uniform overwrite removes input' => ['if ($normalize) { $mode = 0; } else { $mode = 0; } '.$dispatch, 0];
        yield 'input condition with uniform overwrite removes input' => ['if ($mode) { $mode = 0; } else { $mode = 0; } '.$dispatch, 0];
        yield 'missing else preserves input' => ['if (choose()) { $mode = 0; } '.$dispatch, 1];
        yield 'exhaustive elseif overwrites input' => ['if (first()) { $mode = 0; } elseif (second()) { $mode = 1; } else { $mode = 2; } '.$dispatch, 0];
        yield 'elseif without else preserves input' => ['if (first()) { $mode = 0; } elseif (second()) { $mode = 1; } '.$dispatch, 1];
        yield 'returned branch has no continuation' => ['if (choose()) { return null; } else { $mode = 0; } '.$dispatch, 0];
        yield 'throwing branch has no continuation' => ['if (choose()) { throw new RuntimeException; } else { $mode = 0; } '.$dispatch, 0];
        yield 'exiting branch has no continuation' => ['if (choose()) { exit; } else { $mode = 0; } '.$dispatch, 0];
        yield 'terminal expression still dispatches input' => ['if (choose()) { '.$dispatch.' } else { $mode = 0; } '.$dispatch, 1];
        yield 'input selects operations before overwrites' => ['if ($mode === 0) { $mode = 0; create(); } else { $mode = 0; update(); }', 1];
        yield 'input condition still selects branch assigned operation' => ['if ($mode === \'a\') { $mode = 0; } else { $mode = 1; } '.$dispatch, 1];
        yield 'computed input condition still selects branch assigned operation' => ['$selected = $mode === \'a\'; if ($selected) { $mode = 0; } else { $mode = 1; } '.$dispatch, 1];
        yield 'saved alias survives input overwrites' => ['$selected = $mode; if (choose()) { $mode = 0; } else { $mode = 1; } '.$aliasDispatch, 1];
        yield 'all branches overwrite saved alias' => ['$selected = $mode; if (choose()) { $selected = 0; } else { $selected = 1; } '.$aliasDispatch, 0];
        yield 'one continuing alias still preserves input' => ['if (choose()) { $selected = $mode; } else { $selected = 0; } '.$aliasDispatch, 1];
        yield 'nested local write cannot overwrite outer input' => ['$callback = function () { $mode = 0; }; '.$dispatch, 1];
    }

    public static function cases(): iterable
    {
        yield 'switch' => ['switch ($mode) { case "new": create(); break; case "old": update(); break; }', 1];
        yield 'match' => ['return match ($mode) { 0 => create(), 1 => update() };', 1];
        yield 'enum' => ['return match ($mode) { Mode::Create => create(), Mode::Update => update() };', 1];
        yield 'if chain' => ['if ($mode === "new") { create(); } elseif ($mode === "old") { update(); }', 1];
        yield 'reversed equality' => ['if ("new" === $mode) { create(); } else { update(); }', 1];
        yield 'default switch' => ['switch ($mode) { case 0: create(); break; default: update(); }', 1];
        yield 'default match' => ['return match ($mode) { 0 => create(), default => update() };', 1];
        yield 'direct aliases' => ['$first = $mode; $second = $first; return match ($second) { 0 => create(), 1 => update() };', 1];
        yield 'branch alias' => ['if (ready()) { $selected = $mode; } else { $selected = "new"; } return match ($selected) { 0 => create(), 1 => update() };', 1];
        yield 'overwritten alias' => ['$selected = $mode; $selected = 0; return match ($selected) { 0 => create(), 1 => update() };', 0];
        yield 'shared preparation' => ['switch ($mode) { case 0: prepare(); create(); break; case 1: prepare(); update(); break; }', 1];
        yield 'different receivers' => ['return match ($mode) { 0 => $sales->save(), 1 => $billing->save() };', 1];
        yield 'scalar data' => ['return match ($mode) { "en" => "Hello", "fr" => "Bonjour" };', 0];
        yield 'same operation different data' => ['return match ($mode) { "en" => format("Hello"), "fr" => format("Bonjour") };', 0];
        yield 'validation only' => ['if ($mode === "unknown") { throw new InvalidArgumentException(); } create();', 0];
        yield 'match validation failure' => ['return match ($mode) { "valid" => create(), default => throw new InvalidArgumentException() };', 0];
        yield 'if validation failure' => ['if ($mode === "valid") { create(); } else { throw new InvalidArgumentException(); }', 0];
        yield 'switch validation failure' => ['switch ($mode) { case "valid": create(); break; default: throw new InvalidArgumentException(); }', 0];
        yield 'unknown exception construction' => ['return match ($mode) { "valid" => create(), default => throw new UnknownFailure() };', 0];
        yield 'two operations and validation' => ['return match ($mode) { "new" => create(), "old" => update(), default => throw new InvalidArgumentException() };', 1];
        yield 'operation before failure' => ['if ($mode === "new") { create(); } else { update(); throw new InvalidArgumentException(); }', 1];
        yield 'failure constructor argument effect' => ['return match ($mode) { "new" => create(), default => throw new InvalidArgumentException(update()) };', 1];
        yield 'failure factory invocation' => ['return match ($mode) { "new" => create(), default => throw failure() };', 1];
        yield 'returned construction is an operation' => ['return match ($mode) { "new" => create(), default => new UnknownFailure() };', 1];
        yield 'operation multiplicity is preserved' => ['if ($mode === "single") { charge(); } else { charge(); charge(); }', 1];
        yield 'unrelated local selector' => ['$local = random_int(0, 1); return match ($local) { 0 => create(), 1 => update() };', 0];
        yield 'nested scope isolated' => ['$closure = function () { return match ($mode) { 0 => create(), 1 => update() }; };', 0];
    }
}
