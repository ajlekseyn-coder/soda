<?php

declare(strict_types=1);

namespace Cosmira\Soda\Tests;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Config\SodaInitFileEmitter;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Rules\Design\FragmentedOwnerBehavior;
use Cosmira\Soda\Rules\Design\RepeatedConfigurationSequence;
use Cosmira\Soda\Rules\Design\RepeatedForeignStateDecision;
use Cosmira\Soda\Rules\Design\RepeatedOwnerConcern;
use Cosmira\Soda\Rules\Design\ScatteredOwnerConcern;
use InvalidArgumentException;
use OwnerOpportunities\Post;
use OwnerOpportunities\PublicationChecker;
use OwnerOpportunities\Publisher;
use OwnerOpportunities\Unpublisher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnerDesignRulesTest extends TestCase
{
    public function testCopiedConcernAcrossFilesIncludesOwnerRequirements(): void
    {
        $first = $this->archive('Post');
        $second = str_replace('$active', '$enabled', $this->archive('Comment'));
        $findings = $this->findings(new RepeatedOwnerConcern, [$first, $second]);
        $this->assertCount(1, $findings);
        $this->assertSame(2, $findings[0]->value);
        $this->assertSame(1, $findings[0]->threshold);
        $this->assertSame('Domain\\Post', $findings[0]->class);
        $this->assertStringContainsString('concern (trait)', (string) $findings[0]->message);
        $this->assertStringContainsString('Required owner fields: archived', (string) $findings[0]->message);
        $this->assertStringContainsString('Domain\\Comment::restore()', (string) $findings[0]->message);
        $this->assertNotNull($findings[0]->line);
    }

    public function testConcernThresholdsAndDifferentBehavior(): void
    {
        $first = $this->archive('Post');
        $second = $this->archive('Comment');
        $this->assertCount(0, $this->findings(new RepeatedOwnerConcern(minOwners: 3), [$first, $second]));
        $this->assertCount(0, $this->findings(new RepeatedOwnerConcern(minMethods: 4), [$first, $second]));
        $this->assertCount(0, $this->findings(new RepeatedOwnerConcern, [$first, str_replace('! $this->archived', '$this->archived', $second)]));
        $this->assertCount(0, $this->findings(new RepeatedOwnerConcern, [$first, str_replace('bool $active', 'int $active', $second)]));
    }

    #[DataProvider('concernBoundaries')]
    public function testConcernCounterexamples(string $change): void
    {
        $first = $this->archive('Post');
        $second = sprintf($change, $this->archive('Comment'));
        $this->assertCount(0, $this->findings(new RepeatedOwnerConcern, [$first, $second]));
    }

    public static function concernBoundaries(): \Iterator
    {
        yield 'single owner' => ['<?php namespace Other; class Unrelated {}'];
    }

    public function testAlreadySharedTraitDoesNotSuggestCopyExtraction(): void
    {
        $this->assertSame([], $this->findings(new RepeatedOwnerConcern, [<<<'PHP'
<?php
trait Archivable {
    public function archive(): void { $this->archived = true; }
    public function restore(): void { $this->archived = false; }
    public function isActive(): bool { return ! $this->archived; }
}
class Post { use Archivable; private bool $archived = false; }
class Comment { use Archivable; private bool $archived = false; }
PHP]));
    }

    public function testScatteredConcernReportsInterleavingButAcceptsGroupedMethods(): void
    {
        $source = $this->archive('Post');
        $source = str_replace('    public function restore()', '    public function title(): string { return "post"; }\n    public function restore()', $source);
        $source = str_replace('\\n', "\n", $source);

        $findings = $this->findings(new ScatteredOwnerConcern, [$source]);
        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->value);
        $this->assertStringContainsString('Group these methods together', (string) $findings[0]->message);
        $this->assertStringContainsString('Keep the behavior on Domain\\Post', (string) $findings[0]->message);
        $this->assertSame([], $this->findings(new ScatteredOwnerConcern, [$this->archive('Post')]));
    }

    #[DataProvider('uncertainOwners')]
    public function testUnresolvedOwnersDoNotProduceConcernAdvice(string $replacement): void
    {
        $source = str_replace('class Post', $replacement, $this->archive('Post'));
        $this->assertSame([], $this->findings(new ScatteredOwnerConcern, [$source]));
        $this->assertSame([], $this->findings(new RepeatedOwnerConcern, [$source, $this->archive('Comment')]));
    }

    public static function uncertainOwners(): \Iterator
    {
        yield 'inheritance' => ['class Post extends Unknown'];
        yield 'external contract' => ['class Post implements Unknown'];
        yield 'abstract class' => ['abstract class Post'];
    }

    public function testRepeatedForeignQuestionCountsDistinctConsumersAndAliases(): void
    {
        $sources = [$this->owner()];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = '<?php namespace App; use Domain\\Post as Article; function '.$name.'(Article $article): bool { $post = $article; return $post->isPublished() && ! $post->deleted; }';
        }

        $findings = $this->findings(new RepeatedForeignStateDecision, $sources);
        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->value);
        $this->assertStringContainsString('named predicate on Domain\\Post', (string) $findings[0]->message);
        $this->assertStringContainsString('App\\third', (string) $findings[0]->message);
    }

    #[DataProvider('foreignBoundaries')]
    public function testForeignQuestionBoundaries(string $expression, int $expected): void
    {
        $sources = [$this->owner()];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = '<?php namespace App; function '.$name.'(\\Domain\\Post $post, \\Domain\\Post $other, bool $context): bool { return '.$expression.'; }';
        }

        $this->assertCount($expected, $this->findings(new RepeatedForeignStateDecision, $sources));
    }

    public static function foreignBoundaries(): \Iterator
    {
        yield 'known getters and fields' => ['$post->isPublished() && ! $post->deleted', 1];
        yield 'external context' => ['$post->isPublished() && $context', 0];
        yield 'two receivers' => ['$post->isPublished() && ! $other->deleted', 0];
        yield 'unknown getter' => ['$post->unknown() && ! $post->deleted', 0];
        yield 'effectful getter' => ['$post->touch() && ! $post->deleted', 0];
        yield 'getter arguments' => ['$post->isPublished(1) && ! $post->deleted', 0];
        yield 'single read' => ['$post->deleted', 0];
        yield 'nullsafe receiver' => ['$post?->isPublished() && ! $post->deleted', 0];
        yield 'dynamic member' => ['$post->{$context} && ! $post->deleted', 0];
    }

    public function testForeignQuestionDoesNotCountRepeatsInOneConsumer(): void
    {
        $source = '<?php function check(\\Domain\\Post $post): bool { if ($post->isPublished() && ! $post->deleted) {} if ($post->isPublished() && ! $post->deleted) {} return $post->isPublished() && ! $post->deleted; }';
        $this->assertSame([], $this->findings(new RepeatedForeignStateDecision, [$this->owner(), $source]));
    }

    public function testFluentOpportunityAcrossFilesPreservesSequenceEvidence(): void
    {
        $sources = [$this->report()];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = $this->reportScenario($name);
        }

        $findings = $this->findings(new RepeatedConfigurationSequence, $sources);
        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->value);
        $this->assertStringContainsString('setPeriod() -> setFormat() -> download()', (string) $findings[0]->message);
        $this->assertStringContainsString('fluent object', (string) $findings[0]->message);
        $this->assertStringContainsString('execution boundary', (string) $findings[0]->message);
        $this->assertSame([], $this->findings(new RepeatedConfigurationSequence(minOccurrences: 4), $sources));
    }

    #[DataProvider('configurationBoundaries')]
    public function testConfigurationCounterexamples(string $sequence): void
    {
        $sources = [$this->report()];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = '<?php function '.$name.'(string $period): void { '.$sequence.' }';
        }

        $this->assertSame([], $this->findings(new RepeatedConfigurationSequence, $sources));
    }

    public static function configurationBoundaries(): \Iterator
    {
        yield 'already fluent' => ['$r = (new \\Domain\\Report)->setPeriod($period)->setFormat("csv")->download();'];
        yield 'no fresh instance' => ['$r->setPeriod($period); $r->setFormat("csv"); $r->download();'];
        yield 'receiver escapes' => ['$r = new \\Domain\\Report; $r->setPeriod($period); consume($r); $r->setFormat("csv"); $r->download();'];
        yield 'argument exposes receiver' => ['$r = new \\Domain\\Report; $r->setPeriod($r); $r->setFormat("csv"); $r->download();'];
        yield 'argument has effects' => ['$r = new \\Domain\\Report; $r->setPeriod(read()); $r->setFormat("csv"); $r->download();'];
        yield 'wrong argument count' => ['$r = new \\Domain\\Report; $r->setPeriod(); $r->setFormat("csv"); $r->download();'];
        yield 'one configuration call' => ['$r = new \\Domain\\Report; $r->setPeriod($period); $r->download();'];
        yield 'unknown execution' => ['$r = new \\Domain\\Report; $r->setPeriod($period); $r->setFormat("csv"); $r->missing();'];
    }

    public function testFragmentedOperationsRequireUsageAndExcludeContext(): void
    {
        $sources = $this->fragmented();
        $findings = $this->findings(new FragmentedOwnerBehavior, $sources);
        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->value);
        $this->assertStringContainsString('methods on Domain\\Post', (string) $findings[0]->message);
        $this->assertStringContainsString('PublicationChecker::check()', (string) $findings[0]->message);
        $this->assertSame([], $this->findings(new FragmentedOwnerBehavior(minClasses: 4), $sources));
        $this->assertSame([], $this->findings(new FragmentedOwnerBehavior, array_slice($sources, 0, 4)));
        $sources[1] = str_replace('Post $post', 'Post $post, bool $context', $sources[1]);
        $this->assertSame([], $this->findings(new FragmentedOwnerBehavior, $sources));
    }

    public function testDuplicateTypeDeclarationsDoNotInventIndependentOwners(): void
    {
        $this->assertSame([], $this->findings(new RepeatedOwnerConcern, [$this->archive('Post'), $this->archive('Post')]));
    }

    public function testLiteralConfigurationAndReturnedExecutionAreRecognized(): void
    {
        $report = str_replace('    public function download()', '    public function includeTotals(): void { $this->totals = true; }
    public function download()', $this->report());
        $report = str_replace('private string $format;', 'private string $format; private bool $totals = false;', $report);

        $sources = [$report];
        foreach (['first', 'second', 'third'] as $name) {
            $source = str_replace('$r->download();', '$r->includeTotals(); return $r->download();', $this->reportScenario($name));
            $sources[] = $source;
        }

        $findings = $this->findings(new RepeatedConfigurationSequence, $sources);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('includeTotals() -> download()', (string) $findings[0]->message);
    }

    public function testConfigurationDoesNotMistakeEffectfulSettersForSetup(): void
    {
        $report = str_replace('$this->period = $value;', 'notify($value); $this->period = $value;', $this->report());
        $this->assertSame([], $this->findings(new RepeatedConfigurationSequence, [$report, $this->reportScenario('first'), $this->reportScenario('second'), $this->reportScenario('third')]));
        $sources = [$report];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = str_replace([': void', '$r->download();'], ['', 'return $r;'], $this->reportScenario($name));
        }

        $this->assertSame([], $this->findings(new RepeatedConfigurationSequence, $sources));
    }

    public function testSetupOnlyFactoriesSuggestConstructionOnTheOwnerAcrossFiles(): void
    {
        $sources = [$this->report()];
        foreach (['Daily', 'Monthly', 'Annual'] as $name) {
            $sources[] = '<?php namespace Factories; use Domain\\Report as Document; final class '.$name.' {
                public function make(string $period): Document {
                    $r = new Document; $r->setPeriod($period); $r->setFormat("csv"); return $r;
                }
            }';
        }

        $rule = new RepeatedConfigurationSequence;
        $findings = $this->findings($rule, $sources);
        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->value);
        $message = (string) $findings[0]->message;
        $this->assertStringContainsString('setPeriod() -> setFormat() -> return object', $message);
        $this->assertStringContainsString('setup-only factory classes', $message);
        foreach (['Daily', 'Monthly', 'Annual'] as $name) {
            $this->assertStringContainsString('Factories\\'.$name.'::make at ', $message);
        }

        $this->assertSame([], $this->findings($rule, array_slice($sources, 0, 3)));
        $this->assertSame([], $this->findings(new RepeatedConfigurationSequence(minConfigurationCalls: 3), $sources));
        $sources[3] = str_replace('$r->setPeriod($period); $r->setFormat("csv");', '$r->setFormat("csv"); $r->setPeriod($period);', $sources[3]);
        $this->assertSame([], $this->findings($rule, $sources));
    }

    public function testReturnedSetupDoesNotMergeWithExecutedSetup(): void
    {
        $sources = [$this->report(), $this->reportScenario('first'), $this->reportScenario('second')];
        $sources[] = str_replace([': void', '$r->download();'], ['', 'return $r;'], $this->reportScenario('third'));
        $this->assertSame([], $this->findings(new RepeatedConfigurationSequence, $sources));
    }

    #[DataProvider('returnedConfigurationBoundaries')]
    public function testReturnedConfigurationBoundaries(string $ending): void
    {
        $sources = [$this->report()];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = '<?php function '.$name.'(string $period) {
                $r = new \\Domain\\Report; $r->setPeriod($period); '.$ending.'
            }';
        }

        $this->assertSame([], $this->findings(new RepeatedConfigurationSequence, $sources));
    }

    public static function returnedConfigurationBoundaries(): \Iterator
    {
        yield 'one setup step' => ['return $r;'];
        yield 'no returned object' => ['$r->setFormat("csv");'];
        yield 'returned alias' => ['$r->setFormat("csv"); $alias = $r; return $alias;'];
        yield 'escaped receiver' => ['consume($r); $r->setFormat("csv"); return $r;'];
        yield 'computed argument' => ['$r->setFormat(read()); return $r;'];
        yield 'different returned object' => ['$r->setFormat("csv"); return new \\Domain\\Report;'];
        yield 'getter before return' => ['$r->download(); return $r;'];
        yield 'branch boundary' => ['if ($period) { $r->setFormat("csv"); } return $r;'];
        yield 'unreachable setup' => ['return $r; $r->setFormat("csv"); return $r;'];
    }

    public function testCopiedMethodsOverDifferentStateContractsStaySeparate(): void
    {
        $second = str_replace('bool $archived = false', 'int $archived = 0', $this->archive('Comment'));
        $this->assertSame([], $this->findings(new RepeatedOwnerConcern, [$this->archive('Post'), $second]));
    }

    public function testGettersAndSettersAloneDoNotJustifyAConcern(): void
    {
        $source = '<?php class OWNER { private bool $value = false; public function set(bool $value): void { $this->value = $value; } public function get(): bool { return $this->value; } public function clear(): void { $this->value = false; } }';
        $this->assertSame([], $this->findings(new RepeatedOwnerConcern, [str_replace('OWNER', 'First', $source), str_replace('OWNER', 'Second', $source)]));
    }

    public function testUnknownLinksPreventScatteredConcernAdvice(): void
    {
        $source = str_replace('    public function restore()', '    public function other(): void { $this->{$member}(); }
    public function restore()', $this->archive('Post'));
        $this->assertSame([], $this->findings(new ScatteredOwnerConcern, [$source]));
    }

    public function testMultipleAliasesAndReassignmentsDoNotCreateForeignQuestions(): void
    {
        $sources = [$this->owner()];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = '<?php function '.$name.'(\\Domain\\Post $post): bool { $alias = $post; if (unknown()) { $alias = other(); } return $alias->isPublished() && ! $alias->deleted; }';
        }

        $this->assertSame([], $this->findings(new RepeatedForeignStateDecision, $sources));
    }

    public function testNestedFunctionsRemainIndependentFromOuterReceiverBindings(): void
    {
        $sources = [$this->owner()];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = '<?php function '.$name.'(\\Domain\\Post $post): void { $predicate = function () { return $post->isPublished() && ! $post->deleted; }; }';
        }

        $this->assertSame([], $this->findings(new RepeatedForeignStateDecision, $sources));
    }

    public function testPropertyHooksAndUnknownExternalOwnersStayUnknown(): void
    {
        $owner = str_replace('public bool $deleted = false;', 'public bool $deleted { get => false; }', $this->owner());
        $sources = [$owner];
        foreach (['first', 'second', 'third'] as $name) {
            $sources[] = '<?php function '.$name.'(\\Domain\\Post $post): bool { return $post->isPublished() && ! $post->deleted; }';
        }

        $this->assertSame([], $this->findings(new RepeatedForeignStateDecision, $sources));
        $this->assertSame([], $this->findings(new RepeatedForeignStateDecision, array_slice($sources, 1)));
    }

    public function testFragmentedClassesWithStateOrConstructorEffectsStaySeparate(): void
    {
        $sources = $this->fragmented();
        $sources[1] = str_replace('class Publisher {', 'class Publisher { private int $calls = 0;', $sources[1]);
        $this->assertSame([], $this->findings(new FragmentedOwnerBehavior, $sources));
        $sources = $this->fragmented();
        $sources[1] = str_replace('class Publisher {', 'class Publisher { public function __construct() { connect(); }', $sources[1]);
        $this->assertSame([], $this->findings(new FragmentedOwnerBehavior, $sources));
    }

    public function testFactsAreScalarAndCollectionIsOptional(): void
    {
        $files = $this->files([$this->archive('Post')]);

        try {
            $collector = new FactCollector;
            $without = $collector->collect($files[0], []);
            $this->assertSame([], $without->metrics['ownerDesign']);
            $with = $collector->collect($files[0], ['ownerDesign']);
            $this->assertScalarEvidence($with->metrics['ownerDesign']);
        } finally {
            array_map(unlink(...), $files);
        }
    }

    private function assertScalarEvidence(mixed $value): void
    {
        $this->assertIsNotObject($value);
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertScalarEvidence($item);
            }
        }
    }

    public function testCatalogAndStandardConfigEnableOwnerChecks(): void
    {
        foreach ($this->rules() as $rule) {
            $definition = RuleCatalog::definitions()[$rule->id()];
            $this->assertTrue($definition['standard'] ?? true);
            $this->assertSame('warning', $definition['severity']);
            $this->assertArrayHasKey($rule->id(), RuleCatalog::standardDefinitions());
            $this->assertStringContainsString('new '.(new \ReflectionClass($rule))->getShortName().'(', SodaInitFileEmitter::emit());
        }
    }

    #[DataProvider('invalidThresholds')]
    public function testInvalidThresholdsFailImmediately(string $class, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new $class(...$arguments);
    }

    public static function invalidThresholds(): \Iterator
    {
        yield [RepeatedOwnerConcern::class, ['minMethods' => 2]];
        yield [RepeatedOwnerConcern::class, ['minOwners' => 1]];
        yield [ScatteredOwnerConcern::class, ['minMethods' => 2]];
        yield [FragmentedOwnerBehavior::class, ['minClasses' => 1]];
        yield [RepeatedForeignStateDecision::class, ['minConsumers' => 1]];
        yield [RepeatedConfigurationSequence::class, ['minOccurrences' => 1]];
        yield [RepeatedConfigurationSequence::class, ['minConfigurationCalls' => 1]];
    }

    public function testAllFiveRulesRecognizeTheDocumentedScenarioThroughRunner(): void
    {
        $path = __DIR__.'/../fixtures/design/owner-opportunities.php';
        $result = (new Runner)->check([$path], Soda::configure()->with($this->rules())->checks());
        $actual = $result->violations->map(fn ($violation): string => $violation->rule)->sort()->values()->all();
        $expected = array_map(fn (Check $rule): string => $rule->id(), $this->rules());
        sort($expected);
        $this->assertSame($expected, $actual);
        foreach ($result->violations as $violation) {
            $this->assertSame($path, $violation->file);
            $this->assertGreaterThan(1, $violation->line);
            $this->assertStringContainsString('owner-opportunities.php:', (string) $violation->message);
        }
    }

    public function testConcernAndFluentRefactoringPreservesScenarioBehavior(): void
    {
        $directory = __DIR__.'/../fixtures/design/';
        require_once $directory.'owner-opportunities.php';
        require_once $directory.'owner-refactored.php';
        $before = new Post;
        $after = new \OwnerRefactored\Post;
        foreach (['archive', 'restore'] as $operation) {
            $before->$operation();
            $after->$operation();
            $this->assertSame($before->isActive(), $after->isActive());
        }

        (new Publisher)->publish($before);
        $after->publish();
        $this->assertSame((new PublicationChecker)->check($before), $after->isPubliclyVisible());
        (new Unpublisher)->unpublish($before);
        $after->unpublish();
        $this->assertSame((new PublicationChecker)->check($before), $after->isPubliclyVisible());
        $this->assertSame(\OwnerOpportunities\daily('today'), \OwnerRefactored\daily('today'));
        $this->assertSame(\OwnerOpportunities\monthly('month'), \OwnerRefactored\monthly('month'));
        $this->assertSame(\OwnerOpportunities\annual('year'), \OwnerRefactored\annual('year'));
        $result = (new Runner)->check([$directory.'owner-refactored.php'], Soda::configure()->with($this->rules())->checks());
        $this->assertCount(0, $result->violations);
    }

    public function testRunnerAndJsonCarryTheOptionalDiagnostic(): void
    {
        $files = $this->files([$this->archive('Post'), $this->archive('Comment')]);

        try {
            $result = (new Runner)->check($files, Soda::configure()->with([new RepeatedOwnerConcern])->checks());
            $this->assertCount(1, $result->violations);
            $row = $result->violations->first()->toArray();
            $this->assertSame('repeated_owner_concern', $row['rule']);
            $this->assertSame($files[0], $row['file']);
            $this->assertIsInt($row['line']);
            $this->assertStringContainsString($files[1], (string) $row['message']);
        } finally {
            array_map(unlink(...), $files);
        }
    }

    private function rules(): array
    {
        return [new RepeatedOwnerConcern, new ScatteredOwnerConcern, new FragmentedOwnerBehavior, new RepeatedForeignStateDecision, new RepeatedConfigurationSequence];
    }

    private function findings(Check $rule, array $sources): array
    {
        $files = $this->files($sources);
        $project = new ProjectFacts;

        try {
            foreach ($files as $file) {
                $facts = (new FactCollector)->collect($file, $rule->requiredAnalyses());
                $project->add($facts);
            }

            return iterator_to_array($rule->checkProject($project));
        } finally {
            array_map(unlink(...), $files);
        }
    }

    private function files(array $sources): array
    {
        $files = [];
        foreach ($sources as $source) {
            $file = tempnam(sys_get_temp_dir(), 'soda-owner-');
            $this->assertIsString($file);
            file_put_contents($file, $source);
            $files[] = $file;
        }

        return $files;
    }

    private function archive(string $name): string
    {
        return '<?php namespace Domain; class '.$name.<<<'PHP'
 {
    private bool $archived = false;
    public function archive(): void { $this->archived = true; }
    public function restore(): void { $this->archived = false; }
    public function isActive(bool $active = true): bool { return $active && ! $this->archived; }
}
PHP;
    }

    private function owner(): string
    {
        return <<<'PHP'
<?php namespace Domain;
class Post {
    public bool $published = false;
    public bool $deleted = false;
    public function isPublished(): bool { return $this->published; }
    public function touch(): bool { $this->published = true; return $this->published; }
}
PHP;
    }

    private function report(): string
    {
        return <<<'PHP'
<?php namespace Domain;
class Report {
    private string $period;
    private string $format;
    public function setPeriod(string $value): void { $this->period = $value; }
    public function setFormat(string $value): void { $this->format = $value; }
    public function download(): void { deliver($this->period, $this->format); }
}
PHP;
    }

    private function reportScenario(string $name): string
    {
        return '<?php function '.$name.'(string $period): void { $r = new \\Domain\\Report; $r->setPeriod($period); $r->setFormat("csv"); $r->download(); }';
    }

    private function fragmented(): array
    {
        return [
            $this->owner(),
            '<?php namespace Domain; class Publisher { public function publish(Post $post): void { $post->published = true; } }',
            '<?php namespace Domain; class Unpublisher { public function unpublish(Post $post): void { $post->published = false; } }',
            '<?php namespace Domain; class PublicationChecker { public function check(Post $post): bool { return $post->published && ! $post->deleted; } }',
            '<?php namespace App; function run(\\Domain\\Post $post, \\Domain\\Publisher $publisher, \\Domain\\Unpublisher $unpublisher, \\Domain\\PublicationChecker $checker): void { $publisher->publish($post); $unpublisher->unpublish($post); $checker->check($post); }',
        ];
    }
}
