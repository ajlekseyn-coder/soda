<?php

declare(strict_types=1);

namespace Cosmira\Soda\Config;

use Cosmira\Soda\Support\ConfigException;

final class ConfigLoader
{
    /**
     * Limit implicit configuration lookup to ten ancestor directories.
     */
    private const int MAX_DEPTH = 10;

    /**
     * Find and load one configuration, or use the standard rules when none exists.
     *
     * @param list<non-empty-string> $files
     */
    public function resolve(array $files, ?string $explicitPath = null): SodaConfig
    {
        $path = $this->locate($files, $explicitPath);

        return $path !== null ? $this->load($path) : $this->loadDefault();
    }

    /**
     * Reject ambiguous project configurations instead of choosing by file order.
     *
     * @param list<non-empty-string> $files
     *
     * @return non-empty-string|null
     */
    private function locate(array $files, ?string $explicitPath): ?string
    {
        $hasExplicitPath = ($explicitPath ?? '') !== '';
        if ($hasExplicitPath) {
            return $explicitPath;
        }

        $directories = array_unique(array_map(fn (string $file) => is_dir($file) ? $file : dirname($file), $files));
        $paths = [];
        foreach ($directories as $directory) {
            $path = $this->firstConfigInAncestors($directory);
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        $paths = array_unique($paths);
        $hasMultipleConfigs = count($paths) > 1;
        if ($hasMultipleConfigs) {
            throw new ConfigException('Multiple soda.php configs found. Use --config to choose one: '.implode(', ', $paths));
        }

        return array_shift($paths);
    }

    /**
     * Walk project parents directly; no callback or traversal object is needed.
     *
     * @return non-empty-string|null
     */
    private function firstConfigInAncestors(string $directory): ?string
    {
        $current = $directory;
        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $path = $current.'/soda.php';
            $isReadableConfig = is_readable($path) && $this->isExactFilename($current);
            if ($isReadableConfig) {
                return $path;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }

        return null;
    }

    /**
     * Require exact casing even on case-insensitive filesystems.
     */
    private function isExactFilename(string $directory): bool
    {
        $entries = scandir($directory);

        return $entries !== false && in_array('soda.php', $entries, true);
    }

    /**
     * Load the fluent configuration once and reject unsupported exports.
     */
    public function load(string $path): SodaConfig
    {
        $isPhp = str_ends_with($path, '.php');
        if (! $isPhp) {
            throw new ConfigException(sprintf('Config must be a .php file; JSON is no longer supported (%s).', $path));
        }

        $isReadable = is_readable($path);
        throw_unless($isReadable, ConfigException::class, 'Config file not readable: '.$path);

        $config = require $path;
        if ($config instanceof SodaConfig) {
            return $config;
        }

        throw new ConfigException(sprintf('PHP config "%s" must return a %s instance.', $path, SodaConfig::class));
    }

    /**
     * Use the standard bundle when no project configuration exists.
     */
    public function loadDefault(): SodaConfig
    {
        return Soda::configure()->with(RuleCatalog::standard());
    }
}
