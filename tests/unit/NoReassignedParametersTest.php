<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Rules\Usage\NoReassignedParameters;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoReassignedParametersTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testBindingWritesAndPermittedMutation(string $body, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoReassignedParameters, '<?php function run($input) { '.$body.' }'));
    }

    public static function bodies(): array
    {
        return [
            ['$input = normalize($input);', 1],
            ['$input += 1;', 1],
            ['$input ??= fallback();', 1],
            ['$input =& $other;', 1],
            ['++$input; $input++; --$input; $input--;', 4],
            ['unset($input);', 1],
            ['foreach ($items as $input) {}', 1],
            ['foreach ($items as $input => $other) {}', 1],
            ['foreach ($items as &$input) {}', 1],
            ['foreach ($items as [$input, $other]) {}', 1],
            ['try { work(); } catch (Exception $input) {}', 1],
            ['try { work(); } catch (Exception) {}', 0],
            ['[$input, $other] = pair();', 1],
            ['list(, $input) = pair();', 1],
            ['[[$input], $other] = nested();', 1],
            ['static $input = 1;', 1],
            ['global $input;', 1],
            ['$result = normalize($input);', 0],
            ['$input->value = 1; $input->execute();', 0],
            ['$input[0] = 1; $input[] = 2; unset($input[0]);', 0],
            ['$other =& $input; $other = 1;', 0],
            ['mutateByReference($input);', 0],
            ['${$name} = 1;', 0],
            ['$later = function () use ($input) { $input = 1; };', 0],
            ['$later = function () use (&$input) { $input = 1; };', 1],
            ['$later = function () use (&$input) { $nested = function () use (&$input) { $input = 1; }; };', 1],
            ['$later = function () use ($input) { $nested = function () use (&$input) { $input = 1; }; };', 0],
            ['$later = fn () => $input = 1;', 0],
            ['$later = fn ($input) => $input = 1;', 1],
            ['$later = function ($input) { $input = 1; };', 1],
            ['function nested($input) { $input = 1; }', 1],
            ['function nested() { $input = 1; } $input = 2;', 1],
            ['$object = new class { function run($input) { $input = 1; } }; $input = 2;', 2],
            ['$object = new class { function run() { $input = 1; } };', 0],
        ];
    }

    public function testOutputParametersAndExactExceptions(): void
    {
        self::assertSame([], FiveRuleFixture::findings(new NoReassignedParameters, '<?php function run(&$input) { $input = 1; $later = function () use (&$input) { $input = 2; }; }'));
        self::assertSame([], FiveRuleFixture::findings(new NoReassignedParameters(['input']), '<?php function run($input) { $input = 1; $later = function () use (&$input) { $input = 2; }; }'));
        self::assertCount(1, FiveRuleFixture::findings(new NoReassignedParameters(['Input']), '<?php function run($input) { $input = 1; }'));
        self::assertCount(1, FiveRuleFixture::findings(new NoReassignedParameters, '<?php function run(...$input) { $input = []; }'));
        self::assertSame([], FiveRuleFixture::findings(new NoReassignedParameters, '<?php $input = 1; $later = function () use (&$input) { $input = 2; };'));
        self::assertCount(2, FiveRuleFixture::findings(new NoReassignedParameters, '<?php function run($input, $other) { [$input, $other] = pair(); }'), 'Both inputs should be reported');
    }

    public function testLocationsPromotedInputsAndDuplicateTargets(): void
    {
        $source = "<?php\nclass Worker {\nfunction __construct(public \$input) {\n    \$input = 1;\n}\n}";
        $finding = FiveRuleFixture::findings(new NoReassignedParameters, $source)[0];
        self::assertSame('no_reassigned_parameters', $finding->rule);
        self::assertSame(4, $finding->line);
        self::assertSame(1, $finding->value);
        self::assertSame(0, $finding->threshold);
        self::assertStringContainsString('$input', $finding->message);
        self::assertStringContainsString('give a transformed value its own name', $finding->message);
        self::assertCount(1, FiveRuleFixture::findings(new NoReassignedParameters, '<?php function run($input) { [$input, $input] = pair(); }'));
    }

    public function testMultipleReferenceCapturesRetainBothBindingsAndLocations(): void
    {
        $source = <<<'PHP'
<?php
function run($first, $second) {
    $later = function () use (&$first, &$second) {
        $first = 1;
        $second = 2;
    };
}
PHP;
        $findings = FiveRuleFixture::findings(new NoReassignedParameters, $source);
        self::assertCount(2, $findings);
        self::assertSame([4, 5], array_column($findings, 'line'));
        self::assertStringContainsString('$first', $findings[0]->message);
        self::assertStringContainsString('$second', $findings[1]->message);
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidExceptions(array $names): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NoReassignedParameters($names);
    }

    public static function invalidNames(): array
    {
        return [[['']], [['$input']], [[12]]];
    }
}
