<?php

declare(strict_types=1);

namespace Cosmira\Soda\Reporting;

use function basename;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Collection;

use function sprintf;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

use Symfony\Component\Console\Formatter\OutputFormatter;

final readonly class ReportFormatter
{
    /**
     * Defines separator width used by this policy.
     */
    private const int SEPARATOR_WIDTH = 60;

    /**
     * Defines the closing console markup tag used by this formatter.
     */
    private const string TAG_CLOSE = '</>';

    /**
     * Defines the research status assigned to paths whose contract could not be resolved.
     */
    private const string UNKNOWN_STATUS = 'unknown';

    /**
     * Initialize the configured values and collaborators for this instance.
     *
     * @param RuleMetadata $ruleMetadata Supplies labels and policy metadata for reported rule identifiers.
     */
    public function __construct(
        private RuleMetadata $ruleMetadata,
    ) {}

    /**
     * Write using the current analysis state.
     */
    public function write(OutputStyle $output, QualityResult $result, string $projectRoot): void
    {
        $output->writeln([
            '<fg=bright-blue;options=bold>Soda Quality</>',
            '<fg=gray>'.str_repeat('-', self::SEPARATOR_WIDTH).self::TAG_CLOSE,
        ]);
        $output->newLine();

        $n = $result->violations->count();
        if ($n > 0) {
            $output->writeln(sprintf(
                '<fg=default>%s</>',
                OutputFormatter::escape($n === 1 ? '1 issue' : $n.' issues'),
            ));
            $output->newLine();

            $this->writeViolationsGrouped($output, $result->violations, $projectRoot);
            $output->writeln('<fg=gray>'.str_repeat('-', self::SEPARATOR_WIDTH).self::TAG_CLOSE);
            $output->newLine();
        }

        $this->writeResearch($output, $result, $projectRoot);

        $passed = $result->isPassing();
        if ($passed) {
            $output->writeln('<fg=green;options=bold>[OK]</> No issues');

            return;
        }

        $output->writeln(sprintf(
            '<fg=red;options=bold>[FAIL]</> %s',
            $n === 1 ? '1 issue' : $n.' issues',
        ));
    }

    /**
     * Show research paths and incomplete coverage independently of the quality gate.
     */
    private function writeResearch(OutputStyle $output, QualityResult $result, string $projectRoot): void
    {
        foreach ($result->research as $id => $report) {
            $this->writeResearchReport($output, $id, $report, $projectRoot);
            $output->newLine();
        }
    }

    /**
     * Render one research report with its summary line, coverage and collected evidence.
     *
     * @param array<string, mixed> $report
     */
    private function writeResearchReport(OutputStyle $output, string $id, array $report, string $projectRoot): void
    {
        $candidates = $report['candidates'] ?? [];
        $partial = $report['partial'] ?? [];
        $coverage = $report['coverage'] ?? [];
        $unknown = count(array_filter($partial, fn (array $path): bool => ($path['status'] ?? null) === self::UNKNOWN_STATUS));
        $truncated = count(array_filter($partial, fn (array $path): bool => ($path['status'] ?? null) === 'truncated'));

        $output->writeln('<fg=yellow;options=bold>Research (nongating): '.OutputFormatter::escape($id).self::TAG_CLOSE);
        $output->writeln(sprintf(
            '  %d candidates; %d partial paths (unknown: %d; truncated: %d)',
            count($candidates), count($partial), $unknown, $truncated,
        ));
        $this->writeResearchCoverage($output, $coverage);
        $this->writeResearchCandidates($output, $candidates, $projectRoot);
        $this->writeResearchPaths($output, $partial, $projectRoot);
    }

    /**
     * Report coverage limitations that qualify how the research conclusions may be read.
     *
     * @param array<string, mixed> $coverage
     */
    private function writeResearchCoverage(OutputStyle $output, array $coverage): void
    {
        if (($coverage['incomplete'] ?? false) === true) {
            $output->writeln('  Coverage incomplete; absence of candidates does not establish absence of churn.');
        }

        foreach ($coverage['limitations'] ?? [] as $limitation) {
            $output->writeln('  Coverage: '.OutputFormatter::escape($limitation));
        }
    }

    /**
     * List resolved churn candidates with their concept preservation and path points.
     *
     * @param list<array<string, mixed>> $candidates
     */
    private function writeResearchCandidates(OutputStyle $output, array $candidates, string $projectRoot): void
    {
        foreach ($candidates as $candidate) {
            $output->writeln('  Candidate: '.OutputFormatter::escape($candidate['message'] ?? 'Inspect the connected data path and its contracts.'));
            $output->writeln('    Concept preservation: '.OutputFormatter::escape($candidate['concept_preservation'] ?? self::UNKNOWN_STATUS).'; manual review required.');
            foreach ($candidate['path'] ?? [] as $point) {
                $this->writeResearchLocation($output, $point, $projectRoot);
            }
        }
    }

    /**
     * List partial evidence paths that could not be resolved into full candidates.
     *
     * @param list<array<string, mixed>> $partial
     */
    private function writeResearchPaths(OutputStyle $output, array $partial, string $projectRoot): void
    {
        foreach ($partial as $path) {
            $output->writeln('  '.OutputFormatter::escape(($path['status'] ?? self::UNKNOWN_STATUS).': '.($path['reason'] ?? 'Incomplete path evidence.')));
            if (isset($path['file'])) {
                $this->writeResearchLocation($output, $path, $projectRoot);
            }
        }
    }

    /**
     * Render one source point from a research path without prescribing a change.
     *
     * @param array<string, mixed> $point
     */
    private function writeResearchLocation(OutputStyle $output, array $point, string $projectRoot): void
    {
        $location = $this->shortenPath($point['file'], $projectRoot).':'.($point['line'] ?? '?');
        $detail = trim(($point['callable'] ?? '').' '.($point['operation'] ?? ''));
        $output->writeln('    '.OutputFormatter::escape($location.($detail !== '' ? ' '.$detail : '')));
    }

    /**
     * Render diagnostics grouped by file with source locations and remediation advice.
     *
     * @param Collection<int, Violation> $violations
     */
    private function writeViolationsGrouped(OutputStyle $output, Collection $violations, string $projectRoot): void
    {
        /** @var Collection<string, Collection<int, Violation>> $byFile */
        $byFile = $violations->groupBy(fn (Violation $v) => $v->file)->sortKeys();

        foreach ($byFile as $file => $list) {
            $rel = $this->shortenPath($file, $projectRoot);
            $output->writeln('<fg=cyan;options=bold>'.OutputFormatter::escape($rel).self::TAG_CLOSE);

            foreach ($list as $v) {
                $this->writeViolationDetail($output, $v);
            }

            $output->newLine();
        }
    }

    /**
     * Format the measured value and the limit it violates.
     */
    private function formatThresholdDetail(Violation $v): string
    {
        $label = OutputFormatter::escape($this->ruleMetadata->label($v->rule));
        $lim = ['value' => $v->value, 'threshold' => $v->threshold];
        $valueStr = OutputFormatter::escape($this->formatLimitValue($v->rule, $lim['value'], $lim['threshold']));

        return sprintf('<fg=default>%s</> %s', $label, $valueStr);
    }

    /**
     * Make an absolute diagnostic path relative to the displayed project root.
     */
    private function shortenPath(string $path, string $root): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        $hasRoot = $root !== '';
        $isInsideRoot = str_starts_with($path, $root.'/') || $path === $root;

        if ($hasRoot && $isInsideRoot) {
            $cut = substr($path, strlen($root) + 1);
            $hasExtension = $cut !== '' && $cut !== false;

            return $hasExtension ? $cut : basename($path);
        }

        return $path;
    }

    /**
     * Write violation detail using the current analysis state.
     */
    private function writeViolationDetail(OutputStyle $output, Violation $v): void
    {
        $isError = $this->ruleMetadata->severity($v->rule) === RuleMetadata::SEVERITY_ERROR;
        $mark = $isError ? '<fg=red;options=bold>×</>' : '<fg=yellow;options=bold>!</>';
        $hasLine = $v->line !== null;

        $lineCol = $hasLine ? (string) $v->line : '—';
        $scope = $v->method ?? $v->class;
        $locLine = '<options=bold>Line '.$lineCol.self::TAG_CLOSE;
        if ($scope !== null) {
            $locLine .= ' <fg=gray>('.OutputFormatter::escape($scope).')</>';
        }

        $detail = $v->message !== null
            ? OutputFormatter::escape($v->message)
            : $this->formatThresholdDetail($v);
        $output->writeln(sprintf('  %s %s', $mark, $locLine));
        $output->writeln('    '.$detail);

        $advice = $this->ruleMetadata->advice($v->rule);
        if ($advice !== null) {
            $output->writeln('    <fg=gray>↳ '.OutputFormatter::escape($advice).self::TAG_CLOSE);
        }
    }

    /**
     * Render a numeric limit without unnecessary fractional digits.
     */
    private function formatLimitValue(string $rule, int $value, int $threshold): string
    {
        $cmp = $this->ruleMetadata->comparison($rule);

        return sprintf('%d (%s %d)', $value, $cmp, $threshold);
    }
}
