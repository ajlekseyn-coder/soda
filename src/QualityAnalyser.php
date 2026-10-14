<?php

declare(strict_types=1);

namespace Cosmira\Soda;

use Cosmira\Soda\Analysis\Runner;
use Cosmira\Soda\Config\ConfigLoader;
use Cosmira\Soda\Reporting\QualityResult;

/**
 * Load configuration, then run file and project checks over the given paths.
 */
final class QualityAnalyser
{
    /**
     * Resolve configuration for the analysed files and execute the configured checks.
     *
     * @param list<non-empty-string> $files
     */
    public function analyse(array $files, ?string $configPath = null): QualityResult
    {
        $config = (new ConfigLoader)->resolve($files, $configPath);

        return (new Runner)->check($files, $config->checks());
    }
}
