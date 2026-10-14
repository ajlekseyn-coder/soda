<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Rules\Complexity\NoRepeatedStringLiterals;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoRepeatedStringLiteralsTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testLiteralAndMetadataBoundaries(string $body, int $count): void
    {
        self::assertCount($count, FiveRuleFixture::findings(new NoRepeatedStringLiterals, '<?php class Worker { function run() { '.$body.' } }'));
    }

    public static function bodies(): array
    {
        return [
            ["send('ready'); send('ready');", 0],
            ["send('ready'); send('ready'); send('ready');", 1],
            ["send('ready'); send('ready'); send('ready'); send('ready');", 1],
            ["send('ready'); send('READY'); send('Ready');", 0],
            ["send('id'); send('id'); send('id');", 0],
            ["send(''); send(''); send('');", 0],
            ["send('   '); send('   '); send('   ');", 0],
            ["send('123'); send('123'); send('123');", 1],
            ["send('123'); send('123'); send('123'); send('0123'); send('0123'); send('0123');", 2],
            ["send('status'); send(\"status\"); send('status');", 1],
            ["send('ready'); send('ready'); send(\"ready \$value\");", 0],
            ['send("ready {$value}"); send("ready {$value}"); send("ready {$value}");', 0],
            ["send('ready'); send('ready'); send(function () { return 'ready'; });", 1],
            ["send('ready'); send('ready'); function nested() { send('ready'); }", 0],
            ["send('ready'); send('ready'); new class { function run() { send('ready'); } };", 0],
            ["new class { function run() { send('ready'); send('ready'); send('ready'); } };", 1],
            ["send('你好'); send('你好'); send('你好');", 1],
            ["send('я'); send('я'); send('я');", 0],
            ["send('a'.'b'); send('a'.'b'); send('a'.'b');", 0],
            ["send(['status' => 1, 'status' => 2, 'status' => 3]);", 0],
            ['send("\xFF\xFF\xFF"); send("\xFF\xFF\xFF"); send("\xFF\xFF\xFF");', 1],
        ];
    }

    public function testOwnerIsolationAndDeclarativeExclusions(): void
    {
        $source = <<<'PHP'
<?php
namespace App;
#[Label('ready', 'ready', 'ready')]
class Worker {
    const ONE = 'ready';
    const TWO = 'ready';
    const THREE = 'ready';
    public $one = 'ready', $two = 'ready', $three = 'ready';
    function run($one = 'ready', $two = 'ready', $three = 'ready') { send('ready'); send('ready'); }
}
class Other { function run() { send('ready'); } }
PHP;
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals, $source));
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals, '<?php trait Shared { function first() { send("ready"); } function second() { send("ready"); send("ready"); } }'));
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals, '<?php enum State: string { case A="ready"; case B="other"; function run() { send("ready"); send("ready"); send("ready"); } }'));
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals, '<?php interface Contract { const A="ready", B="ready", C="ready"; } function run() { send("ready"); send("ready"); send("ready"); }'));
    }

    public function testPropertyHookBehaviorIsIncludedButDefaultsAreNot(): void
    {
        $source = '<?php class Worker { public string $status = "ready" { get { send("ready"); send("ready"); return "ready"; } } }';
        $findings = FiveRuleFixture::findings(new NoRepeatedStringLiterals, $source);
        self::assertCount(1, $findings);
        self::assertSame(3, $findings[0]->value);
    }

    public function testDecodedStringsAndHeredocAreGrouped(): void
    {
        $source = <<<'PHP'
<?php
class Worker { function run() {
    send('ready');
    send("\x72eady");
    send(<<<'TEXT'
ready
TEXT);
} }
PHP;
        $finding = FiveRuleFixture::findings(new NoRepeatedStringLiterals, $source)[0];
        self::assertSame(3, $finding->value);
        self::assertStringContainsString('"ready"', $finding->message);
    }

    public function testArrayKeysAreExcludedUntilExplicitlyIncluded(): void
    {
        $source = '<?php class Worker { function run() { send(["status" => 1, "status" => 2, "status" => 3]); } }';
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals, $source));
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals(includeArrayKeys: true), $source));
    }

    public function testThresholdsExceptionsAndEvidence(): void
    {
        $source = "<?php\nnamespace App;\nclass Worker { function run() {\n send('ready');\n send('ready');\n send('ready');\n send('ready');\n} }";
        $finding = FiveRuleFixture::findings(new NoRepeatedStringLiterals, $source)[0];
        self::assertSame('no_repeated_string_literals', $finding->rule);
        self::assertSame('App\Worker', $finding->class);
        self::assertSame(6, $finding->line);
        self::assertSame(4, $finding->value);
        self::assertSame(2, $finding->threshold);
        self::assertStringStartsWith('Repeated string "ready" at lines 4, 5, 6, 7.', $finding->message);
        self::assertStringContainsString('Name a shared protocol value when it has one meaning', $finding->message);
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals(5), $source));
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals(4), $source));
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals(minLength: 6), $source));
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals(ignoredStrings: ['ready']), $source));
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals(ignoredStrings: ['READY']), $source));
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals(2, 2), '<?php class Worker { function run() { send("id"); send("id"); } }'));
        $anonymous = FiveRuleFixture::findings(new NoRepeatedStringLiterals, '<?php new class { function run() { send("ready"); send("ready"); send("ready"); } };')[0];
        self::assertSame('{anonymous-class@1}', $anonymous->class);
    }

    public function testMetadataAndInterpolationDoNotCreateExpressionOccurrences(): void
    {
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals, '<?php class Worker { #[Label("ready", "ready", "ready")] function run() {} }'));
        self::assertSame([], FiveRuleFixture::findings(new NoRepeatedStringLiterals, '<?php class Worker { function run() { send("state {$value[lookup(\'ready\')]}"); send("state {$value[lookup(\'ready\')]}"); send("state {$value[lookup(\'ready\')]}"); } }'));
    }

    public function testSmallestLengthAndLaterQualifyingGroups(): void
    {
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals(2, 1), '<?php class Worker { function run() { send("x"); send("x"); } }'));
        $source = '<?php class Worker { function run() { send("other"); send("ready"); send("ready"); send("ready"); } }';
        self::assertCount(1, FiveRuleFixture::findings(new NoRepeatedStringLiterals, $source));
        $source = '<?php class Worker { function run() { send("other"); send("other"); send("other"); send("ready"); send("ready"); send("ready"); } }';
        $findings = FiveRuleFixture::findings(new NoRepeatedStringLiterals(ignoredStrings: ['other']), $source);
        self::assertCount(1, $findings);
        self::assertStringStartsWith('Repeated string "ready"', $findings[0]->message);
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRejected(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NoRepeatedStringLiterals(...$arguments);
    }

    public static function invalidOptions(): array
    {
        return [[[1]], [[0]], [[3, 0]], [[3, -1]], [[3, 3, [12]]]];
    }
}
