<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Complexity\NoIdenticalBranches;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoIdenticalBranchesTest extends TestCase
{
    #[DataProvider('expressions')]
    public function testExpressionBoundaries(string $expression, int $count): void
    {
        self::assertCount($count, $this->findings('<?php $result = '.$expression.';'));
    }

    public static function expressions(): array
    {
        return [
            'identical literals'       => ['$ready ? "sent" : "sent"', 1],
            'identical calls'          => ['$ready ? send($message) : send($message)', 1],
            'formatting and comments'  => ['$ready ? send( $message ) : /* reason */ send($message)', 1],
            'different results'        => ['$ready ? "sent" : "draft"', 0],
            'different scalar types'   => ['$ready ? 1 : "1"', 0],
            'different arguments'      => ['$ready ? send($first) : send($second)', 0],
            'different argument order' => ['$ready ? send($a, $b) : send($b, $a)', 0],
            'shorthand fallback'       => ['$value ?: $value', 0],
            'null fallback'            => ['$value ?? $value', 0],
            'repeated match result'    => ['match ($channel) { 1 => send($message), 2 => send($message) }', 1],
            'already grouped match'    => ['match ($channel) { 1, 2 => send($message), default => fail() }', 0],
            'default is a boundary'    => ['match ($channel) { 1 => send($message), default => send($message) }', 0],
            'single match arm'         => ['match ($channel) { 1 => send($message) }', 0],
            'different match results'  => ['match ($channel) { 1 => send($message), 2 => queue($message) }', 0],
            'line identity'            => ['$ready ? __LINE__ : __LINE__', 0],
            'nested magic constant'    => ['$ready ? logLine(__LINE__) : logLine(__LINE__)', 0],
            'closure identity'         => ['$ready ? function () { return 1; } : function () { return 1; }', 0],
            'arrow identity'           => ['$ready ? fn () => 1 : fn () => 1', 0],
            'anonymous class identity' => ['$ready ? new class {} : new class {}', 0],
        ];
    }

    public function testGroupsLocationsAndKeepsDiagnosticEvidence(): void
    {
        $findings = $this->findings(<<<'PHP'
<?php
$result = match ($channel) {
    1 => send($message),
    2 => send($message),
    3 => send($message),
    4 => queue($message),
    5 => queue($message),
    default => send($message),
};
PHP);
        self::assertCount(2, $findings);
        self::assertSame([4, 7], array_column($findings, 'line'));
        self::assertSame([2, 1], array_column($findings, 'value'));
        self::assertSame('no_identical_branches', $findings[0]->rule);
        self::assertSame('/branches.php', $findings[0]->file);
        self::assertSame(0, $findings[0]->threshold);
        self::assertStringContainsString('3, 4, 5', $findings[0]->message);
        self::assertStringContainsString('preserve condition evaluation', $findings[0]->message);
        self::assertSame(4, $findings[0]->toArray()['line']);
    }

    public function testNestedCallablesAreCheckedIndependently(): void
    {
        $source = '<?php $result = $ready ? fn () => $inner ? 1 : 1 : fn () => 2;';
        self::assertCount(1, $this->findings($source));
    }

    public function testSwitchFallThroughAndStatementBranchesAreOutsidePolicy(): void
    {
        self::assertSame([], $this->findings('<?php switch ($value) { case 1: send(); break; case 2: send(); break; } if ($ready) { send(); } else { send(); }'));
    }

    public function testStandardRunnerEnablesTheRule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-branches-');
        self::assertIsString($path);
        file_put_contents($path, '<?php $result = match ($value) { 1 => "sent", 2 => "sent" };');

        try {
            $result = (new Runner)->check([$path], Soda::configure()->with(RuleCatalog::standard())->checks());
            $findings = $result->violations->filter(fn ($item): bool => $item->rule === 'no_identical_branches')->values()->all();
            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            unlink($path);
        }
    }

    private function findings(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);

        return iterator_to_array((new NoIdenticalBranches)->checkFile(new FileFacts('/branches.php', $source, $nodes, [])));
    }
}
