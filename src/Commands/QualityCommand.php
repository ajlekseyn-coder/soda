<?php

declare(strict_types=1);

namespace Cosmira\Soda\Commands;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\ConfigLoader;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Reporting\QualityJsonReportFormatter;
use Cosmira\Soda\Reporting\QualityResult;
use Cosmira\Soda\Reporting\ReportFormatter;
use Cosmira\Soda\Reporting\ResearchJsonReportFormatter;
use Cosmira\Soda\Reporting\RuleMetadata;
use Cosmira\Soda\Support\ConfigException;

use function file_put_contents;
use function getcwd;

use Illuminate\Console\Command;

use function is_string;
use function json_encode;
use function preg_match;
use function rtrim;

use SebastianBergmann\FileIterator\Facade;

final class QualityCommand extends Command
{
    /**
     * Compose the command with the runner executing the configured checks.
     *
     * @param Runner $runner Runs the configured checks and assembles the quality report.
     */
    public function __construct(
        private readonly Runner $runner,
    ) {
        parent::__construct();
    }

    /**
     * Console signature accepted by the quality command.
     */
    protected $signature = 'quality
        {path?* : Directory or directories to analyze}
        {--suffix= : Include files with names ending in suffix (default: .php)}
        {--exclude=* : Exclude files with path in their path}
        {--config= : Path to soda.php}
        {--report-json= : Write quality report to JSON file}
        {--research-json= : Write nongating research evidence to a separate JSON file}
    ';

    /**
     * Summary shown by `soda list`.
     */
    protected $description = 'Analyze code quality and check against configured thresholds';

    /**
     * Execute the console command and return its exit status.
     */
    public function handle(): int
    {
        /** @var list<non-empty-string> $directories */
        $directories = (array) $this->argument('path');

        $root = getcwd();
        $root = ($root !== false && $root !== '') ? $root : '/';

        $result = $this->analyze($directories, $root);
        if (! $result instanceof QualityResult) {
            return self::FAILURE;
        }

        (new ReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->write($this->output, $result, $root);
        $this->writeReportJson($result);
        $this->writeResearchJson($result);

        return $result->isPassing() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Load configuration, expand the requested inputs and run every configured check.
     *
     * @param list<non-empty-string> $requested
     */
    private function analyze(array $requested, string $root): ?QualityResult
    {
        try {
            $configOption = $this->option('config');
            $configPath = is_string($configOption) && $configOption !== '' ? $configOption : null;
            $config = $requested === [] ? (new ConfigLoader)->resolve([$root], $configPath) : null;
            $directories = $config?->paths() ?? $requested;

            if ($directories === []) {
                $this->error('No directory specified. Add withPaths([...]) to soda.php or pass a path.');

                return null;
            }

            $files = $this->resolveFiles($directories, []);
            $config ??= (new ConfigLoader)->resolve($files, $configPath);
            if ($config->excludedPaths() !== []) {
                $files = $this->resolveFiles($directories, $config->excludedPaths());
            }

            if ($files === []) {
                $this->error('No files found to scan');

                return null;
            }

            return $this->runner->check($files, $config->checks());
        } catch (ConfigException $configException) {
            $this->error($configException->getMessage());

            return null;
        }
    }

    /**
     * Vendor code is not part of the project being assessed. FileIterator expects
     * exclusion paths rather than path fragments, so expand relative CLI values
     * against every requested analysis root.
     *
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $requested
     *
     * @return list<non-empty-string>
     */
    private function exclusionPaths(array $directories, array $requested): array
    {
        $relative = ['vendor', ...$requested];
        $paths = [];

        foreach ($relative as $exclude) {
            if ($this->isAbsolutePath($exclude)) {
                $paths[] = $exclude;

                continue;
            }

            foreach ($directories as $directory) {
                $paths[] = rtrim($directory, '/\\').'/'.$exclude;
            }
        }

        return array_values(array_unique(array_map(
            fn (string $path): string => is_dir($path) ? rtrim($path, '/\\').DIRECTORY_SEPARATOR : $path,
            $paths,
        )));
    }

    /**
     * Determine whether absolute path applies to the supplied input.
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    /**
     * Expand configured or command-line paths into the PHP files to analyze.
     *
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $excludedPaths Configuration paths combined with CLI exclusions.
     *
     * @return list<non-empty-string>
     */
    private function resolveFiles(array $directories, array $excludedPaths): array
    {
        $suffixOpt = $this->option('suffix');
        $suffixes = $suffixOpt === null ? ['.php'] : array_merge(['.php'], (array) $suffixOpt);
        /** @var list<non-empty-string> $suffixes */
        $exclude = $this->exclusionPaths(
            $directories,
            [...$excludedPaths, ...(array) ($this->option('exclude') ?? [])],
        );
        /** @var list<non-empty-string> $exclude */

        return (new Facade)->getFilesAsArray($directories, $suffixes, '', $exclude);
    }

    /**
     * Write report json using the current analysis state.
     */
    private function writeReportJson(QualityResult $result): void
    {
        $reportJson = $this->option('report-json');
        $isMissingReport = ! is_string($reportJson) || $reportJson === '';

        if ($isMissingReport) {
            return;
        }

        $data = (new QualityJsonReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->format($result);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents($reportJson, $json);
    }

    /**
     * Write opt-in research evidence without changing the quality JSON schema.
     */
    private function writeResearchJson(QualityResult $result): void
    {
        $path = $this->option('research-json');
        if (! is_string($path) || $path === '') {
            return;
        }

        $data = (new ResearchJsonReportFormatter)->format($result);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json);
    }
}
