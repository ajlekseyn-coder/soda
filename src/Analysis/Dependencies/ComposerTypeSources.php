<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis\Dependencies;

/**
 * Reads Composer's JSON package metadata without loading its executable autoloader.
 */
final readonly class ComposerTypeSources
{
    /**
     * Discover package and installed PSR-4 sources enclosing the analyzed files.
     */
    public static function discover(array $files): self
    {
        $metadata = [];
        foreach ($files as $file) {
            foreach (self::metadataFor($file) as $path) {
                $metadata[$path] = true;
            }
        }

        $prefixes = [];
        $archives = [];
        foreach (array_keys($metadata) as $path) {
            $sources = self::sourcesFrom($path);
            $archives = [...$archives, ...$sources->archives];
            foreach ($sources->prefixes as $prefix => $roots) {
                $prefixes[$prefix] = [...($prefixes[$prefix] ?? []), ...$roots];
            }
        }

        uksort($prefixes, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return new self($prefixes, array_values(array_unique($archives)));
    }

    /**
     * The closest package manifest owns local prefixes; an enclosing installation supplies dependencies.
     */
    private static function metadataFor(string $file): array
    {
        $paths = [];
        $projectFound = false;
        $directory = dirname($file);
        while (dirname($directory) !== $directory) {
            $project = $directory.'/composer.json';
            if (! $projectFound && is_file($project)) {
                $paths[] = $project;
                $projectFound = true;
            }

            $installed = $directory.'/vendor/composer/installed.json';
            if (is_file($installed)) {
                $paths[] = $installed;

                break;
            }

            $directory = dirname($directory);
        }

        return $paths;
    }

    /**
     * Convert package installation paths and their PSR-4 declarations into source roots.
     */
    private static function sourcesFrom(string $path): self
    {
        $project = basename($path) === 'composer.json';
        $prefixes = [];
        $archives = [];
        foreach (self::packagesIn($path) as $package) {
            $autoload = $package['autoload'] ?? [];
            $installPath = $package['install-path'] ?? null;
            if (! is_string($installPath)) {
                continue;
            }

            $packageRoot = dirname($path).'/'.$installPath;
            $packageArchives = $project ? [] : glob($packageRoot.'/*.{phar,zip,tar}', GLOB_BRACE);
            $archives = [...$archives, ...($packageArchives === false ? [] : $packageArchives)];

            foreach ($autoload['psr-4'] ?? [] as $prefix => $roots) {
                $directories = $prefixes[$prefix] ?? [];
                foreach ((array) $roots as $root) {
                    $directories[] = dirname($path).'/'.$installPath.'/'.$root;
                }

                $prefixes[$prefix] = $directories;
            }
        }

        return new self($prefixes, $archives);
    }

    /**
     * Normalize the two Composer metadata formats without evaluating PHP autoload files.
     */
    private static function packagesIn(string $path): array
    {
        $content = file_get_contents($path);
        $decoded = $content === false ? null : json_decode($content, true);
        $metadata = is_array($decoded) ? $decoded : [];

        return basename($path) === 'composer.json' ? [self::projectPackage($metadata)] : ($metadata['packages'] ?? []);
    }

    /**
     * Root packages declare development prefixes alongside their production autoload roots.
     */
    private static function projectPackage(array $metadata): array
    {
        $autoload = $metadata['autoload'] ?? [];
        $development = $metadata['autoload-dev'] ?? [];

        return [
            'install-path' => '.',
            'autoload'     => ['psr-4' => array_merge_recursive($autoload['psr-4'] ?? [], $development['psr-4'] ?? [])],
        ];
    }

    /**
     * Store only source directories declared by Composer.
     *
     * @param array<string, list<string>> $prefixes Composer namespace prefixes.
     * @param list<string>                $archives Archives shipped by installed Composer packages.
     */
    private function __construct(private array $prefixes, private array $archives) {}

    /**
     * Locate source candidates; callers verify the declared qualified name.
     *
     * @return list<string>
     */
    public function locate(string $class): array
    {
        $paths = [];

        foreach ($this->prefixes as $prefix => $roots) {
            $matchesPrefix = str_starts_with(strtolower($class), strtolower($prefix));
            if (! $matchesPrefix) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            foreach ($roots as $root) {
                $path = rtrim($root, '/').'/'.$relative;
                if (is_file($path)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths !== [] ? $paths : $this->archiveCandidates($class);
    }

    /**
     * Find PHP entries in configured archives matching the requested class basename.
     *
     * @return list<string>
     */
    private function archiveCandidates(string $class): array
    {
        $name = basename(str_replace('\\', '/', $class)).'.php';
        $paths = [];

        foreach ($this->archives as $archive) {
            $path = realpath($archive);

            if ($path === false) {
                continue;
            }

            foreach ($this->archiveFiles($path) as $file) {
                $matchesName = strcasecmp($file->getFilename(), $name) === 0;
                if ($file->isFile() && $matchesName) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        return $paths;
    }

    /**
     * Iterate archive contents through Phar or PharData when the archive can be opened.
     *
     * @return iterable<\PharFileInfo>
     */
    private function archiveFiles(string $path): iterable
    {
        try {
            try {
                $contents = new \Phar($path);
            } catch (\UnexpectedValueException) {
                $contents = new \PharData($path);
            }

            yield from new \RecursiveIteratorIterator($contents);
        } catch (\UnexpectedValueException) {
            return;
        }
    }
}
