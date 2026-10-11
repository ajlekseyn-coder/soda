<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\AstFacts;
use Cosmira\Soda\Analysis\LogicalLineMap;
use Cosmira\Soda\Analysis\Representation\RepresentationFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Design\RepresentationChurn;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class RepresentationFactsTest extends TestCase
{
    public function testResolvedContractsAndPromotionContainOnlyScalarFacts(): void
    {
        $facts = $this->collect(<<<'PHP'
<?php namespace Domain;
use Vendor\Text as ImportedText;
final readonly class Text {
    public function __construct(public string $value) {}
    public function value(): string { return $this->value; }
    private static function wrap(?ImportedText $text = null): Text { return new Text(value: 'ready'); }
    final public function converted(int &$number): string { return (string) ($number + 1); }
}
function make(Text $text): Text { return new Text($text->value()); }
PHP);
        $type = $facts['types']['domain\\text'];
        $this->assertTrue($type['eligible']);
        $this->assertTrue($type['final']);
        $this->assertTrue($type['readonly']);
        $this->assertSame(['value' => 'string'], $type['properties']);
        $constructor = $facts['callables'][$type['constructor']];
        $this->assertSame('assign', $constructor['statements'][0]['op']);
        $this->assertSame('value', $constructor['statements'][0]['target']['name']);
        $this->assertSame('this', $constructor['statements'][0]['target']['receiver']['name']);
        $wrap = $facts['callables']['domain\\text::wrap'];
        $this->assertTrue($wrap['static']);
        $this->assertSame('private', $wrap['visibility']);
        $this->assertSame('?vendor\\text', $wrap['parameters'][0]['type']);
        $this->assertSame(['op' => 'literal', 'value' => null, 'type' => 'null', 'line' => 6], $wrap['parameters'][0]['default']);
        $this->assertSame('domain\\text', $wrap['statements'][0]['value']['class']);
        $this->assertSame('value', $wrap['statements'][0]['value']['args'][0]['name']);
        $converted = $facts['callables']['domain\\text::converted'];
        $this->assertTrue($converted['parameters'][0]['byRef']);
        $this->assertTrue($converted['final']);
        $this->assertSame('cast', $converted['statements'][0]['value']['op']);
        $this->assertSame('+', $converted['statements'][0]['value']['value']['operator']);
        $this->assertSame('domain\\text', $facts['callables']['domain\\make']['parameters'][0]['type']);
        $this->assertNoObjects($facts);
        $this->assertSame($facts, json_decode(json_encode($facts, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testCallsFunctionsArraysAndConcatenationAreRetained(): void
    {
        $facts = $this->collect(<<<'PHP'
<?php namespace App;
use function Domain\format as encode;
function run(string $value): array {
    $alias = $value;
    helper($alias);
    return ['result' => encode('prefix:'.$alias), 1, true];
}
function helper(string $value): string { return $value; }
PHP);
        $statements = $facts['callables']['app\\run']['statements'];
        $this->assertSame('assign', $statements[0]['op']);
        $this->assertSame('app\\helper', $statements[1]['value']['name']);
        $array = $statements[2]['value'];
        $this->assertSame('array', $array['op']);
        $this->assertSame('domain\\format', $array['items'][0]['value']['name']);
        $this->assertSame('.', $array['items'][0]['value']['args'][0]['value']['operator']);
        $this->assertSame('int', $array['items'][1]['value']['type']);
        $this->assertSame('bool', $array['items'][2]['value']['type']);
    }

    public function testUnsupportedSyntaxAndDeclarationsRemainExplicit(): void
    {
        $facts = $this->collect(<<<'PHP'
<?php
class Child extends ParentType { public function inherited(): string { return parent::load(); } }
trait T {}
class UsesTrait { use T; }
class Magic { public function __get(string $name): mixed { return null; } }
class Hooked { public string $value { get => 'computed'; } }
abstract class AbstractType { abstract public function load(): string; }
function run($input): mixed {
    $alias =& $input;
    if ($input) { return 'branch'; }
    foreach ($input as $value) {}
    $callback = fn () => $input;
    return $input->{$input}();
}
PHP);
        foreach (['child', 'usestrait', 'magic', 'hooked'] as $id) {
            $this->assertFalse($facts['types'][$id]['eligible']);
        }
        $this->assertSame('missing body', $facts['callables']['abstracttype::load']['statements'][0]['reason']);
        $statements = $facts['callables']['run']['statements'];
        $this->assertSame('unknown', $statements[0]['value']['op']);
        $this->assertSame('Expr_AssignRef', $statements[0]['value']['reason']);
        $this->assertSame('barrier', $statements[1]['op']);
        $this->assertSame('barrier', $statements[2]['op']);
        $this->assertSame('unknown', $statements[3]['value']['op']);
        $this->assertSame('unknown', $statements[4]['value']['op']);
    }

    public function testBudgetReportsTruncationAndStopsRetainingFacts(): void
    {
        $facts = $this->collect('<?php function longFlow() { '.str_repeat('$value = "text";', 1000).' }');
        $this->assertCount(1, $facts['truncated']);
        $this->assertSame('longflow', $facts['truncated'][0]['callable']);
        $this->assertSame('fact budget exceeded', $facts['truncated'][0]['reason']);
        $this->assertLessThan(513, count($facts['callables']['longflow']['statements']));
        $statements = $facts['callables']['longflow']['statements'];
        $this->assertSame('barrier', end($statements)['op']);
    }

    public function testFactsAreCollectedOnlyForRequestedAnalysis(): void
    {
        $source = '<?php function value(): string { return "x"; }';
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $metrics = AstFacts::collect($parser->parse($source) ?? [], LogicalLineMap::fromSource($source), []);
        $this->assertArrayNotHasKey('representationFlow', $metrics);
        $metrics = AstFacts::collect($parser->parse($source) ?? [], LogicalLineMap::fromSource($source), ['representationFlow']);
        $this->assertArrayHasKey('value', $metrics['representationFlow']['callables']);
    }

    public function testConditionalNestedAndDuplicateDeclarationsProduceExplicitGaps(): void
    {
        $facts = $this->collect(<<<'PHP'
<?php namespace App;
if ($enabled) { function conditional(string $value): string { return $value; } }
function outer(): void { function nested(string $value): string { return $value; } }
function duplicate(string $value): string { return $value; }
function DUPLICATE(string $value): string { return 'constant'; }
if ($enabled) { final class ConditionalType { public function process(string $value): string { return $value; } } }
class Twice { public function first(string $value): string { return $value; } }
class TWICE { public function second(string $value): string { return $value; } }
PHP);
        foreach (['app\\conditional', 'app\\nested', 'app\\conditionaltype::process'] as $id) {
            $this->assertSame('Conditional or nested declaration.', $facts['callables'][$id]['statements'][0]['reason']);
        }
        foreach (['app\\duplicate', 'app\\twice::first', 'app\\twice::second'] as $id) {
            $this->assertSame('Ambiguous duplicate declaration.', $facts['callables'][$id]['statements'][0]['reason']);
        }
        $this->assertFalse($facts['types']['app\\conditionaltype']['eligible']);
        $this->assertFalse($facts['types']['app\\twice']['eligible']);
        $this->assertSame([], $facts['truncated']);
    }

    public function testUnsupportedPropertyStorageDoesNotBecomeTransparentInstanceStorage(): void
    {
        $facts = $this->collect(<<<'PHP'
<?php
final class StaticStorage { public static string $value; }
final class DefaultStorage { public string $value = 'default'; }
final class ReadonlyStorage { public readonly string $value; public function __construct(string $value) { $this->value = $value; } }
PHP);
        $this->assertFalse($facts['types']['staticstorage']['eligible']);
        $this->assertFalse($facts['types']['defaultstorage']['eligible']);
        $this->assertTrue($facts['types']['readonlystorage']['eligible']);
        $this->assertSame([], $facts['truncated']);
    }

    public function testBudgetInsideReturnRejectsEntireBodyBeforeAnyWitnessCanBeRetained(): void
    {
        $source = '<?php '.$this->flowTypes().' function run(Str $text): Str { return new Str('.str_repeat('"prefix".', 600).'clean(extractText($text))); }';
        $facts = $this->collect($source);
        $this->assertSame('run', $facts['truncated'][0]['callable']);
        $this->assertSame([['op' => 'barrier', 'reason' => 'fact budget exceeded', 'line' => 5]], $facts['callables']['run']['statements']);
        $report = $this->representationReport($source);
        $this->assertSame([], $report['candidates']);
        $this->assertTrue($report['coverage']['incomplete']);
    }

    public function testEngineRejectsConditionalNestedDuplicateAndUnsafeStorageProofs(): void
    {
        $pipeline = ' function run(Str $text): Str { return new Str(clean(extractText($text))); }';
        $positive = $this->representationReport('<?php '.$this->flowTypes().$pipeline);
        $this->assertNotEmpty($positive['candidates']);
        $base = $this->flowTypes(false);
        $clean = 'function clean(string $value): string { return trim($value); }';
        foreach (['if ($enabled) { '.$clean.' }', 'function outer(): void { '.$clean.' }',
            $clean.' function CLEAN(string $value): string { return "unrelated"; }'] as $declarations) {
            $report = $this->representationReport('<?php '.$base.$declarations.$pipeline);
            $this->assertSame([], $report['candidates']);
            $this->assertNotEmpty($report['partial']);
        }
        foreach (['public static string $storage;', 'public string $storage = "default";'] as $field) {
            $source = str_replace('final class Str {', 'final class Str { '.$field, $this->flowTypes());
            $report = $this->representationReport('<?php '.$source.$pipeline);
            $this->assertSame([], $report['candidates']);
            $this->assertNotEmpty($report['partial']);
        }
    }

    public function testEarlyReturnCannotBypassCallableCollectionTruncation(): void
    {
        $source = '<?php '.$this->flowTypes().' function run(Str $text): Str { return new Str(clean(extractText($text))); '
            .str_repeat('$unused = "tail";', 1000).' }';
        $facts = $this->collect($source);
        $this->assertSame('barrier', $facts['callables']['run']['statements'][0]['op']);
        $this->assertCount(1, $facts['callables']['run']['statements']);
        $report = $this->representationReport($source);
        $this->assertSame([], $report['candidates']);
        $this->assertTrue($report['coverage']['incomplete']);
    }

    private function flowTypes(bool $withCleaner = true): string
    {
        return 'final class Str { public function __construct(public string $value) {} public function value(): string { return $this->value; } }'."\n"
            .'function extractText(Str $text): string { return $text->value(); }'."\n"
            .($withCleaner ? 'function clean(string $value): string { return trim($value); }' : '')."\n\n";
    }

    private function representationReport(string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'soda-representation-collector-');
        $this->assertIsString($path);
        file_put_contents($path, $source);

        try {
            return (new Runner)->check([$path], Soda::configure()->with([new RepresentationChurn]))->research['representation_churn'];
        } finally {
            unlink($path);
        }
    }

    private function collect(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];

        return RepresentationFacts::collect((new NodeTraverser(new NameResolver))->traverse($nodes));
    }

    private function assertNoObjects(array $facts): void
    {
        foreach ($facts as $value) {
            $this->assertNotInstanceOf(Node::class, $value);
            $this->assertFalse(is_object($value));
            if (is_array($value)) {
                $this->assertNoObjects($value);
            }
        }
    }
}
