<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\AstFacts;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\LogicalLineMap;
use Cosmira\Soda\Rules\Complexity\MaxBooleanConditions;
use Cosmira\Soda\Rules\Complexity\NoComplexControlConditions;
use Cosmira\Soda\Tests\ParsesPhpSnippets;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoComplexControlConditionsTest extends TestCase
{
    use ParsesPhpSnippets;

    #[DataProvider('conditions')]
    public function testReadableConditionsAndExplanations(string $condition, ?string $reason): void
    {
        foreach ([$condition, str_replace(['$user', ' && ', ' || '], ['$account', "\n && ", "\n || "], $condition)] as $expression) {
            $file = $this->facts('<?php if ('.$expression.') {}');
            $violations = [...(new NoComplexControlConditions)->checkFile($file)];
            self::assertCount($reason === null ? 0 : 1, $violations, $expression);
            if ($reason !== null) {
                self::assertSame($reason, $file->metrics['complexControlConditions'][0]['reason']);
                self::assertSame(1, $violations[0]->line);
                self::assertStringContainsString($reason === 'mixed_logic' ? 'Group mixed logical' : 'Nested computation', $violations[0]->message);
            }
        }
    }

    public static function conditions(): iterable
    {
        foreach ([
            '($found = find())', '($found ??= find())', '($value = $source) === null', 'is_null($value = $source)',
            '(bool) predicate()', '(!$ready) === true', '$ready ?? false', 'str_contains((string) $name, "::")', '__LINE__ > 0',
            '$ready', '!$ready', '!$user->isActive()', '!isset($user)', 'empty($items)', '!($user instanceof User)',
            '$user->age() >= 18 && $user->isVerified()', 'count($items) > 0', 'count($left) === count($right)',
            '$a > $b && $a > $c', '$a && ($b && !$c)', '$a || ($b || !$c)', '$a and $b && $c',
            '!($a && $b)', 'in_array($status, ["ready", "sent"], true)', '$user?->isActive()',
            '$values["ready"] === true', 'Options::READY === $status',
            'array_all($items, fn ($item) => $item->a() && ($item->b() || $item->c()))',
            'check(function () { return nested(work()); })',
            'Auth::user() instanceof User', '($renderer = renderer()) instanceof Renderer',
            '! (output() instanceof BufferedOutput)', 'Env::get("TESTS") ?? false',
            '$ready ?? predicate()', 'primary() ?? fallback()',
        ] as $expression) {
            yield $expression => [$expression, null];
        }
        foreach (['$a && $b || $c', '$a xor $b || $c', '!($a || $b && $c)'] as $expression) {
            yield $expression => [$expression, 'mixed_logic'];
        }
        foreach ([
            'Order::query()->pending()->count() > $limit', 'check(resolve($user))', 'getUser()->isReady()',
            '($a > $b) === true', '$count > $limit * 1000', '($ready ? one() : two()) === 1',
            '(match ($value) { 1 => true, default => false })', 'check([resolve($user)])',
            '$values[index()] === true', '$user->{name()}()',
            'resolve(input()) instanceof User', 'Env::get(key()) ?? false',
            '(primary() ?? fallback())->ready()', 'check(Env::get("TESTS") ?? false)',
        ] as $expression) {
            yield $expression => [$expression, null];
        }

        yield 'unloaded or absent actor without lazy loading' => [
            '! $record->relationLoaded("actor") || $record->actor === null', null,
        ];
        yield 'personal contact or explicitly unsupported primary type' => [
            '$isPersonal || PerimeterContactType::tryFrom($contactType)?->canBePrimary() === false', null,
        ];
        yield 'null photo or non-PostgreSQL connection' => [
            '$value === null || $this->getConnection()->getDriverName() !== "pgsql"', null,
        ];
        yield 'regular expression with output captures' => [
            'preg_match("/(?:^|Response code: |Consul returned HTTP )([1-5]\\d{2})(?::|;|$)/", $message, $matches) === 1', null,
        ];
    }

    public function testVisitsAllConditionsAndKeepsCallableScopesSeparate(): void
    {
        $file = $this->facts(<<<'PHP'
<?php
if ($a) {} elseif ($a && $b || $c) {}
while ($a && $b || $c) {}
do {} while ($a && $b || $c);
for (; $ready, $a && $b || $c; ) {}
$result = ($a && $b || $c) ? 'yes' : 'no';
if (check(function () { if ($a && $b || $c) {} })) {}
PHP);
        self::assertSame([2, 3, 4, 5, 6, 7], array_column($file->metrics['complexControlConditions'], 'line'));
        self::assertCount(6, [...(new NoComplexControlConditions)->checkFile($file)]);
    }

    public function testLengthRemainsTheBooleanCountRulesResponsibility(): void
    {
        $file = $this->facts('<?php function run() { if ($a && $b && $c && $d && $e) {} }');
        self::assertSame([], [...(new NoComplexControlConditions)->checkFile($file)]);
        self::assertCount(1, [...(new MaxBooleanConditions(2))->checkFile($file)]);
    }

    private function facts(string $source): FileFacts
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $metrics = AstFacts::collect($nodes, LogicalLineMap::fromSource($source), ['conditions'], $source);
        $all = AstFacts::collect((new ParserFactory)->createForNewestSupportedVersion()->parse($source), LogicalLineMap::fromSource($source), null, $source);
        self::assertSame($all['complexControlConditions'], $metrics['complexControlConditions']);

        return new FileFacts('conditions.php', $source, $nodes, $metrics);
    }

    #[DataProvider('pipelineRegressions')]
    public function testPipelinePreservesValidCodeAndDetectsControl(string $valid, string $invalid): void
    {
        $this->assertRulePipeline('no_complex_control_conditions', $valid, $invalid);
    }

    public static function pipelineRegressions(): iterable
    {
        yield 'Pail: a single query with null fallback is a simple condition' => [
            'function testing() { if (Env::get("TESTS") ?? false) { runTests(); } }',
            'function testing() { if ($ready && $testing || $forced) { runTests(); } }',
        ];
        yield 'Prompts and Pail: a single query may be tested with instanceof' => [
            'function render() { if (output() instanceof BufferedOutput) { flushOutput(); } }',
            'function render() { if ($ready || $buffered && $forced) { flushOutput(); } }',
        ];
    }
}
