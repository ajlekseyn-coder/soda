<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Reporting\QualityResult;
use Cosmira\Soda\Support\ConfigException;

/**
 * Facade-style entry for code-quality rule evaluation.
 */
final class Analyzer
{
    /**
     * Analyze one PHP source file using the selected configuration.
     *
     * @param non-empty-string $path
     */
    public static function file(string $path): QualityAnalysisBuilder
    {
        return new QualityAnalysisBuilder([$path]);
    }

    /**
     * Prepare a fluent analysis builder for the supplied source paths.
     *
     * @param list<non-empty-string> $paths
     */
    public static function paths(array $paths): QualityAnalysisBuilder
    {
        return new QualityAnalysisBuilder($paths);
    }

    /**
     * Run analysis over the supplied files with an optional configuration file.
     *
     * @param list<non-empty-string> $paths
     * @param non-empty-string|null  $configPath
     *
     * @throws ConfigException
     */
    public static function analyze(array $paths, ?string $configPath = null): QualityResult
    {
        $builder = self::paths($paths)->config($configPath);

        return $builder->analyse();
    }
}
