<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\ConfigLoader;
use Cosmira\Soda\Reporting\QualityResult;
use Cosmira\Soda\Support\ConfigException;

/**
 * Fluent entry for running quality analysis (reads like a sentence).
 */
final class QualityAnalysisBuilder
{
    /**
     * Configuration file used when the analysis executes; null selects the discovered default.
     */
    private ?string $configPath = null;

    /**
     * Retain source paths for a fluent analysis request.
     *
     * @param list<non-empty-string> $paths
     */
    public function __construct(
        /**
         * Source paths to resolve when the fluent analysis request is executed.
         */
        private readonly array $paths,
    ) {}

    /**
     * Select the configuration file used when executing this analysis request.
     *
     * @param non-empty-string|null $path
     */
    public function config(?string $path): self
    {
        $this->configPath = $path;

        return $this;
    }

    /**
     * Resolve the registered source paths and execute analysis with the selected configuration.
     *
     * @throws ConfigException
     */
    public function analyze(): QualityResult
    {
        $config = (new ConfigLoader)->resolve($this->paths, $this->configPath);

        return (new Runner)->check($this->paths, $config->checks());
    }
}
