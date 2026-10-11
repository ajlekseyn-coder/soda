<?php

declare(strict_types=1);

namespace Cosmira\Soda\Verification;

use Cosmira\Soda\Analysis\FactCollector;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\Soda;
use Cosmira\Soda\Rules\Design\RepresentationChurn;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
final class RepresentationFlowPerformanceTest extends TestCase
{
    private const FLOWS = 180;

    /** @return array{string, list<string>} */
    private function generateProject(): array
    {
        $directory = sys_get_temp_dir().'/soda-representation-performance-'.uniqid();
        mkdir($directory, 0700);
        $files = [$directory.'/Text.php'];
        file_put_contents($files[0], '<?php namespace RepresentationPerformance; final readonly class Text { public function __construct(private string $value) {} public function value(): string { return $this->value; } }');
        for ($index = 0; $index < self::FLOWS; $index++) {
            $sources = [
                'Normalize' => 'function normalize'.$index.'(Text $input): string { return trim($input->value()); }',
                'Clean'     => 'function clean'.$index.'(string $input): string { return str_replace("obsolete", "", $input); }',
                'Pipeline'  => 'function pipeline'.$index.'(Text $input): Text { $normalized = normalize'.$index.'($input); $cleaned = clean'.$index.'($normalized); return new Text($cleaned); }',
            ];
            foreach ($sources as $stage => $source) {
                $file = $directory.'/'.$stage.$index.'.php';
                file_put_contents($file, '<?php namespace RepresentationPerformance; use function trim, str_replace; '.$source);
                $files[] = $file;
            }
        }

        return [$directory, $files];
    }

    private function removeProject(string $directory, array $files): void
    {
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($directory);
    }

    private function containsOnlyScalarFacts(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if (! $this->containsOnlyScalarFacts($child)) {
                    return false;
                }
            }

            return true;
        }

        return is_scalar($value) || $value === null;
    }

    #[RunInSeparateProcess]
    public function testOptionalCollectionRetainsOnlyScalarProjectFactsWithinResourceBudgets(): void
    {
        [$directory, $files] = $this->generateProject();
        $baseline = memory_get_usage(true);
        $start = hrtime(true);

        try {
            $collector = new FactCollector;
            $disabled = $collector->collect($files[0], []);
            self::assertArrayNotHasKey('representationFlow', $disabled->metrics);
            unset($disabled);
            $project = new ProjectFacts;
            foreach ($files as $path) {
                $file = $collector->collect($path, ['representationFlow']);
                self::assertArrayHasKey('representationFlow', $file->metrics);
                $project->add($file);
                unset($file);
            }
            self::assertCount(1 + self::FLOWS * 3, $project->files);
            self::assertTrue($this->containsOnlyScalarFacts($project->files), 'Retained project facts must contain no source objects or AST nodes.');
            $report = (new RepresentationChurn(maxSteps: 100_000, maxCandidates: 500))->researchProject($project);
            self::assertCount(self::FLOWS, $report['candidates']);
            self::assertFalse($report['coverage']['truncated']);
            self::assertSame(2 + self::FLOWS * 3, $report['coverage']['callables']);
            foreach ($report['candidates'] as $candidate) {
                self::assertGreaterThanOrEqual(3, count(array_unique(array_column($candidate['path'], 'file'))));
            }
            self::assertLessThan(12.0, (hrtime(true) - $start) / 1_000_000_000);
            self::assertLessThan(96 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
            self::assertLessThan(256 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            $this->removeProject($directory, $files);
        }
    }

    #[RunInSeparateProcess]
    public function testGlobalWorkBudgetDisclosesUnsearchedCallablesWithoutFailingQuality(): void
    {
        [$directory, $files] = $this->generateProject();
        $baseline = memory_get_usage(true);
        $start = hrtime(true);
        $budget = 250;

        try {
            $result = (new Runner)->check($files, Soda::configure()->with([new RepresentationChurn(maxSteps: $budget)]));
            self::assertTrue($result->isPassing());
            self::assertCount(0, $result->violations);
            $report = $result->research['representation_churn'];
            self::assertSame($budget, $report['limits']['maxSteps']);
            self::assertLessThanOrEqual($budget, $report['coverage']['operations']);
            self::assertLessThan($report['coverage']['callables'], $report['coverage']['roots']);
            self::assertTrue($report['coverage']['incomplete']);
            self::assertTrue($report['coverage']['truncated']);
            $truncations = array_filter($report['partial'], static fn (array $row): bool => $row['status'] === 'truncated' && $row['limit'] === 'maxSteps');
            self::assertNotEmpty($truncations, 'Exhausted work must disclose its limit and source position.');
            foreach ($truncations as $row) {
                self::assertContains($row['file'], $files);
                self::assertGreaterThan(0, $row['line']);
            }
            self::assertLessThan(12.0, (hrtime(true) - $start) / 1_000_000_000);
            self::assertLessThan(96 * 1024 * 1024, memory_get_peak_usage(true) - $baseline);
            self::assertLessThan(256 * 1024 * 1024, memory_get_peak_usage(true));
        } finally {
            $this->removeProject($directory, $files);
        }
    }
}
