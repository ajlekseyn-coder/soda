<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Contracts\ResearchCheck;
use Cosmira\Soda\Rules\Architecture\NoEnvironmentReads;
use Cosmira\Soda\Rules\Structure\MaxClassesPerProject;
use Cosmira\Soda\Rules\Structure\MaxPublicMethods;
use PHPUnit\Framework\TestCase;

final class CheckExclusionsTest extends TestCase
{
    private string $directory;

    private array $files = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/soda-rule-exclusions-'.uniqid();
        $sources = [
            'Clients/Example.php'      => '<?php class ParentClient { public function alpha() {} public function beta() {} public function gamma() {} } env("CLIENT");',
            'Clients/Nested/Child.php' => '<?php class NestedClient { public function alpha() {} public function beta() {} public function gamma() {} } env("NESTED");',
            'Testing/ExampleFake.php'  => '<?php class FakeClient extends ParentClient { public function delta() {} } env("FAKE");',
            'ClientsExtra/Other.php'   => '<?php class OtherClient { public function alpha() {} public function beta() {} public function gamma() {} } env("OTHER");',
        ];
        foreach ($sources as $relative => $source) {
            $path = $this->directory.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $source);
            $this->files[] = $path;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
        }
        foreach (['Clients/Nested', 'Clients', 'Testing', 'ClientsExtra', ''] as $relative) {
            rmdir($this->directory.($relative === '' ? '' : '/'.$relative));
        }
    }

    public function testFileExclusionsSkipOnlyThatConfiguredRuleInstance(): void
    {
        $restricted = (new NoEnvironmentReads)->exclude([$this->directory.'/Clients/*']);
        $result = (new Runner)->check($this->files, [$restricted, new NoEnvironmentReads]);

        self::assertCount(6, $result->violations);
        self::assertCount(1, $result->violations->where('file', $this->files[0]));
        self::assertCount(2, $result->violations->where('file', $this->files[2]));
        self::assertCount(2, $result->violations->where('file', $this->files[3]));
    }

    public function testProjectExclusionsPreserveOtherRulesAndInheritedChildSurface(): void
    {
        $rule = (new MaxPublicMethods(2))->exclude([$this->directory.'/Clients/*']);
        $result = (new Runner)->check($this->files, [$rule, new NoEnvironmentReads]);
        $publicMethods = $result->violations->where('rule', 'max_public_methods')->keyBy('class');

        self::assertCount(2, $publicMethods);
        self::assertSame(4, $publicMethods['FakeClient']->value);
        self::assertSame(3, $publicMethods['OtherClient']->value);
        self::assertCount(4, $result->violations->where('rule', 'no_environment_reads'));
    }

    public function testProjectCountsUseOnlyTheFilesAllowedForThatRule(): void
    {
        $restricted = (new MaxClassesPerProject(2))->exclude([$this->directory.'/Clients/*']);
        $result = (new Runner)->check($this->files, [$restricted, new MaxClassesPerProject(3)]);

        self::assertCount(1, $result->violations);
        self::assertSame(4, $result->violations->first()->value);
        self::assertSame(3, $result->violations->first()->threshold);
    }

    public function testExclusionsAreAdditiveAndRespectPathBoundaries(): void
    {
        $rule = new MaxPublicMethods(2);
        self::assertFalse($rule->isExcludedPath($this->files[0]));
        self::assertSame($rule, $rule->exclude([$this->directory.'/Clients/*']));
        self::assertSame($rule, $rule->exclude([$this->files[2]]));
        self::assertTrue($rule->isExcludedPath($this->files[0]));
        self::assertTrue($rule->isExcludedPath($this->files[1]));
        self::assertTrue($rule->isExcludedPath($this->files[2]));
        self::assertFalse($rule->isExcludedPath($this->files[3]));
    }

    public function testRelativeMasksMatchDirectorySegmentsAtAnyDepth(): void
    {
        $rule = (new NoEnvironmentReads)->exclude(['*/Clients/*']);
        $result = (new Runner)->check($this->files, [$rule]);

        self::assertEqualsCanonicalizing([$this->files[2], $this->files[3]], $result->violations->pluck('file')->all());
    }

    public function testBasenameMasksApplyAtEveryDepth(): void
    {
        $rule = (new NoEnvironmentReads)->exclude(['Example*.php']);
        $result = (new Runner)->check($this->files, [$rule]);

        self::assertEqualsCanonicalizing([$this->files[1], $this->files[3]], $result->violations->pluck('file')->all());
    }

    public function testResearchUsesTheSamePerRuleProjectBoundary(): void
    {
        $rule = new class extends ResearchCheck
        {
            public function id(): string
            {
                return 'source_paths';
            }

            public function requiredAnalyses(): array
            {
                return [];
            }

            public function researchProject(ProjectFacts $project): array
            {
                return ['files' => array_keys($project->files)];
            }
        };
        $rule->exclude([$this->directory.'/Clients/*']);
        $result = (new Runner)->check($this->files, [$rule]);

        self::assertSame([$this->files[2], $this->files[3]], $result->research['source_paths']['files']);
    }

    public function testExcludingEveryRuleDoesNotSuppressParserErrors(): void
    {
        file_put_contents($this->files[0], '<?php class Broken {');
        $rule = (new NoEnvironmentReads)->exclude([$this->directory]);
        $result = (new Runner)->check($this->files, [$rule]);

        self::assertCount(1, $result->violations);
        self::assertSame('parse_error', $result->violations->first()->rule);
    }
}
