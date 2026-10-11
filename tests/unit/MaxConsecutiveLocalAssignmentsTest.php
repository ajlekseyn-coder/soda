<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Rules\Complexity\MaxConsecutiveLocalAssignments;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaxConsecutiveLocalAssignmentsTest extends TestCase
{
    public function testInclusiveDefaultAndEachMaximalRun(): void
    {
        $assignments = static fn (int $count): string => implode(' ', array_map(fn ($i): string => '$value'.$i.' = calculate();', range(1, $count)));
        self::assertSame([], FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments, '<?php function run() { '.$assignments(8).' }'));
        $source = "<?php\nfunction run() {\n".$assignments(9)."\n}";
        $finding = FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments, $source)[0];
        self::assertSame('max_consecutive_local_assignments', $finding->rule);
        self::assertSame(9, $finding->value);
        self::assertSame(8, $finding->threshold);
        self::assertSame(3, $finding->line);
        self::assertStringContainsString('$value1', $finding->message);
        self::assertStringContainsString('$value9', $finding->message);
        self::assertStringContainsString('do not introduce wrappers solely to lower the count', $finding->message);
        $source = '<?php function run() { $a=1; $b=2; $c=3; execute(); $d=4; $e=5; $f=6; }';
        $findings = FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(2), $source);
        self::assertCount(2, $findings);
        self::assertSame([3, 3], array_column($findings, 'value'));
    }

    #[DataProvider('bodies')]
    public function testInitialBindingAndSequenceBoundaries(string $body, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(2), '<?php function run($input) { '.$body.' }'));
    }

    public static function bodies(): array
    {
        return [
            ['$a=1; $b=2; $c=3;', 1],
            ['$a=1; /* reason */ ; $b=2; $c=3;', 1],
            ['$a=1; $b=prepare($a); $c=prepare($b);', 1],
            ['$input=1; $a=2; $b=3;', 0],
            ['$a=1; $a=2; $b=3; $c=4;', 0],
            ['$a=1; execute(); $b=2; $c=3;', 0],
            ['$a=1; $input++; $b=2; $c=3;', 0],
            ['$a=1; $input+=1; $b=2; $c=3;', 0],
            ['$a=1; $input[0]=1; $b=2; $c=3;', 0],
            ['$a=1; $input->value=1; $b=2; $c=3;', 0],
            ['$a=1; [$b,$c]=pair(); $d=4;', 0],
            ['$a=1; $b=$c=2; $d=4;', 0],
            ['$a=1; $b =& $input; $c=3;', 0],
            ['${$name}=1; $a=2; $b=3;', 0],
            ['consume($a); $a=1; $b=2; $c=3;', 0],
            ['if ($ready) { $a=1; } $a=2; $b=3; $c=4;', 0],
            ['if ($ready) { $a=1; $b=2; $c=3; }', 0],
            ['foreach ($items as $a) {} $a=1; $b=2; $c=3;', 0],
            ['global $a; $a=1; $b=2; $c=3;', 0],
            ['static $a=1; $a=2; $b=3; $c=4;', 0],
            ['$callback=function () { $a=1; }; execute(); $a=1; $b=2; $c=3;', 1],
            ['$callback=function () use ($a) {}; execute(); $a=1; $b=2; $c=3;', 0],
            ['$callback=function () use ($a) { $b=1; $c=2; $d=3; };', 1],
            ['$callback=fn () => ($a=1);', 0],
            ['function nested() { $a=1; $b=2; $c=3; }', 1],
            ['$object=new class { function run() { $a=1; $b=2; $c=3; } };', 1],
            ['return;', 0],
        ];
    }

    public function testScopeIsolationAndZero(): void
    {
        self::assertCount(1, FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(0), '<?php function run() { $a=1; }'));
        self::assertSame([], FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(0), '<?php function run($a) { $a=1; }'));
        self::assertSame([], FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(0), '<?php $a=1; $b=2;'));
        self::assertSame([], FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(0), '<?php interface Contract { function run(); }'));
        self::assertCount(2, FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(1), '<?php function first() { $a=1; $b=2; } function second() { $a=1; $b=2; }'));
        self::assertSame([], FiveRuleFixture::findings(new MaxConsecutiveLocalAssignments(1), '<?php $callback=function ($a) use ($b) { $a=1; $b=2; $c=3; };'));
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MaxConsecutiveLocalAssignments(-1);
    }
}
