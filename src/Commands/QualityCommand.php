<?php

declare(strict_types=1);

namespace Cosmira\Soda\Commands;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\ConfigLoader;
use Cosmira\Soda\Config\RuleCatalog;
use Cosmira\Soda\Reporting\QualityJsonReportFormatter;
use Cosmira\Soda\Reporting\QualityJunitReportFormatter;
use Cosmira\Soda\Reporting\QualityResult;
use Cosmira\Soda\Reporting\QualityTeamCityReportFormatter;
use Cosmira\Soda\Reporting\ReportFormatter;
use Cosmira\Soda\Reporting\ResearchJsonReportFormatter;
use Cosmira\Soda\Reporting\RuleMetadata;
use Cosmira\Soda\Support\ConfigException;
use Cosmira\Soda\Support\FnmatchPathExclusion;

use function explode;
use function file_put_contents;
use function getcwd;

use Illuminate\Console\Command;

use function implode;
use function in_array;
use function is_string;
use function json_encode;
use function preg_match;
use function rtrim;

use SebastianBergmann\FileIterator\Facade;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class QualityCommand extends Command
{
    /**
     * Report formats accepted by the --report-type option.
     */
    private const REPORT_TYPES = ['json', 'junit', 'teamcity'];

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
        {--report-type= : Report format: json, junit or teamcity}
        {--report-file= : Write the report to this file instead of stdout}
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
        if (! $this->hasValidReportType()) {
            $this->error('The report type must be one of: json, junit, teamcity.');

            return self::FAILURE;
        }

        /** @var list<non-empty-string> $directories */
        $directories = (array) $this->argument('path');

        $root = getcwd();
        $root = ($root !== false && $root !== '') ? $root : '/';

        $result = $this->analyze($directories, $root);
        if (! $result instanceof QualityResult) {
            return self::FAILURE;
        }

        (new ReportFormatter(new RuleMetadata(RuleCatalog::definitions())))->write($this->output, $result, $root);
        $this->writeReport($result);
        $this->writeResearchJson($result);

        return $result->isPassing() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Verify the requested report type names a supported format.
     */
    private function hasValidReportType(): bool
    {
        $type = $this->option('report-type');

        return in_array($type, self::REPORT_TYPES, true)
            || ($type === null && ! $this->input->hasParameterOption('--report-type'));
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
     * Relative exclusions apply to each analysis root, including the automatic
     * vendor boundary. Absolute exclusions remain independent of those roots.
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

        return array_values(array_unique($paths));
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
        $requested = [...$excludedPaths, ...(array) ($this->option('exclude') ?? [])];
        $exclude = $this->exclusionPaths($directories, $requested);
        /** @var list<non-empty-string> $exclude */
        $iterator = new Facade;
        $files = $iterator->getFilesAsArray($directories, $suffixes);
        $excludedFiles = [
            ...$iterator->getFilesAsArray($exclude, $suffixes),
            ...array_filter(array_map(realpath(...), $exclude), fn (string|false $path): bool => $path !== false),
        ];

        $exclusion = new FnmatchPathExclusion(
            patterns: array_values(array_filter(
                [...$exclude, ...$requested],
                fn (string $path): bool => strpbrk($path, '*?[') !== false,
            )),
            pathPrefixes: [],
        );

        return array_values(array_filter(
            array_diff($files, $excludedFiles),
            fn (string $file): bool => ! $exclusion->isExcludedPath($file),
        ));
    }

    /**
     * Emit the requested report to stdout or the requested file.
     */
    private function writeReport(QualityResult $result): void
    {
        $type = $this->option('report-type');
        if (! is_string($type)) {
            return;
        }

        $metadata = new RuleMetadata(RuleCatalog::definitions());
        $report = match ($type) {
            'json'     => $this->jsonReport($result, $metadata),
            'junit'    => (new QualityJunitReportFormatter($metadata))->format($result),
            'teamcity' => implode("\n", (new QualityTeamCityReportFormatter($metadata))->format($result))."\n",
        };

        $file = $this->option('report-file');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, $report);

            return;
        }

        foreach (explode("\n", rtrim($report, "\n")) as $line) {
            $this->output->writeln(OutputFormatter::escape($line));
        }
    }

    /**
     * Serialize the machine-readable JSON report.
     */
    private function jsonReport(QualityResult $result, RuleMetadata $metadata): string
    {
        $data = (new QualityJsonReportFormatter($metadata))->format($result);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
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
