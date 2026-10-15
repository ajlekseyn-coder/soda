<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Usage;

use Cosmira\Soda\Analysis\Composition\BehaviorComposition;
use Cosmira\Soda\Analysis\Composition\ClassBehaviorFacts;
use Cosmira\Soda\Analysis\Dependencies\ExternalParameterContracts;
use Cosmira\Soda\Analysis\FileFacts;
use Cosmira\Soda\Analysis\Frameworks\LaravelMiddlewareContracts;
use Cosmira\Soda\Analysis\InheritedParameterContracts;
use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\Usage\ParameterInputFacts;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;

/**
 * Reports unused inputs while preserving positions required by known parent contracts.
 */
final class NoUnusedParameters extends Check
{
    /**
     * Register callable contracts whose parameters are required by external consumers.
     *
     * @param list<string> $contracts Exact qualified method/function names with externally fixed signatures.
     */
    public function __construct(
        /**
         * External callable contracts exempted from unused-parameter diagnostics.
         */
        private readonly array $contracts = [],
    ) {}

    /**
     * Direct file checks resolve local declarations; the runner defers to complete project facts.
     */
    public function checkFile(FileFacts $file): iterable
    {
        if (array_key_exists('parameterInputs', $file->metrics)) {
            return;
        }

        $types = $this->fileContracts($file->path, InheritedParameterContracts::collect($file->nodes));
        $inherited = new InheritedParameterContracts($types);
        $surfaces = (new BehaviorComposition)->resolve(ClassBehaviorFacts::collect($file->nodes));
        yield from $this->findings(
            $file->path, ParameterInputFacts::collect($file->nodes), $inherited,
            LaravelMiddlewareContracts::collect($file->nodes), $this->composedSignatures($types, $surfaces, $inherited),
        );
    }

    /**
     * Resolve contracts after every file's declarations have been collected.
     */
    public function checkProject(ProjectFacts $project): iterable
    {
        $types = [];
        $middleware = [];
        foreach ($project->files as $path => $facts) {
            $types += $this->fileContracts($path, $facts['parameterContracts'] ?? []);
            $middleware += $facts['registeredMiddleware'] ?? [];
        }

        $types = ExternalParameterContracts::resolve($types, array_keys($project->files));
        $inherited = new InheritedParameterContracts($types);
        $surfaces = (new BehaviorComposition)->project($project);
        $signatures = $this->composedSignatures($types, $surfaces, $inherited);
        foreach ($project->files as $path => $facts) {
            yield from $this->findings($path, $facts['parameterInputs'] ?? [], $inherited, $middleware, $signatures);
        }
    }

    /**
     * Anonymous source offsets are unique only within their own file.
     */
    private function fileContracts(string $path, array $types): array
    {
        $qualified = [];
        foreach ($types as $name => $type) {
            $qualified[$this->contractKey($path, $name)] = $type;
        }

        return $qualified;
    }

    /**
     * A path digest keeps case-sensitive filenames distinct in a case-insensitive type index.
     */
    private function contractKey(string $path, string $name): string
    {
        return str_starts_with($name, '{anonymous}') ? hash('sha256', $path).'#'.$name : $name;
    }

    /**
     * Preserve required positions without exempting extra inputs on the same method.
     */
    private function findings(string $path, array $candidates, InheritedParameterContracts $inherited, array $middleware, array $signatures = []): iterable
    {
        $contracts = array_fill_keys(array_map(strtolower(...), $this->contracts), true);
        $unresolved = array_fill_keys(array_keys($signatures, null, true), true);
        foreach ($candidates as $candidate) {
            $identity = $candidate['identity'];
            $owner = $this->contractKey($path, $candidate['contractClass'] ?? $candidate['class'] ?? '');
            $arity = max($signatures[strtolower($identity)] ?? 0, $inherited->arity($owner, $candidate['method']));
            $registered = isset($middleware[strtolower($candidate['class'] ?? '')]);
            $terminate = $registered && strtolower($candidate['method'] ?? '') === 'terminate' && ($candidate['publicInstance'] ?? false);
            $arity = max($arity, $terminate ? 2 : 0);
            $isExternal = isset($contracts[strtolower($identity)]);
            $isPolymorphic = $inherited->isUsedByOverride($owner, $candidate['method'], $candidate['position']);
            $signature = strtolower($owner.'::'.$candidate['method']);
            $isUnresolved = isset($unresolved[$signature]);
            if ($isExternal || $candidate['position'] < $arity || $isPolymorphic || $isUnresolved) {
                continue;
            }

            yield new Violation(
                rule: $this->id(), file: $path, value: 1, threshold: 0,
                method: $candidate['method'], class: $candidate['class'], line: $candidate['line'],
                message: sprintf(
                    'Parameter $%s is never used by %s. Remove it if the signature is owned here; preserve required contract or positional callback parameters.',
                    $candidate['name'], $identity !== '' ? $identity : 'this callable',
                ),
            );
        }
    }

    /**
     * Inherited positions are known arities; null marks a signature with unresolved ancestors.
     */
    private function composedSignatures(array $types, array $surfaces, InheritedParameterContracts $contracts): array
    {
        $signatures = [];
        foreach ($surfaces as $class => $surface) {
            foreach ($surface['methods'] as $name => $method) {
                $origin = $method['origin'];
                $signatures[$origin] = max($signatures[$origin] ?? 0, $contracts->arity($class, $name));
            }
        }

        return array_replace($signatures, $this->unresolvedSignatures($types, $surfaces));
    }

    /**
     * Preserve shared trait declarations when any consumer has an unresolved signature.
     */
    private function unresolvedSignatures(array $types, array $surfaces): array
    {
        $signatures = [];
        foreach ($types as $name => $type) {
            if (! $this->hasUnknownAncestor($name, $types, [])) {
                continue;
            }

            foreach ($type['methods'] ?? [] as $method => $signature) {
                if (! $signature['private']) {
                    $signatures[$name.'::'.$method] = null;
                }
            }

            foreach (($surfaces[$name] ?? [])['methods'] ?? [] as $method => $signature) {
                if ($signature['visibility'] !== 'private') {
                    $signatures[strtolower($signature['origin'])] = null;
                }
            }
        }

        return $signatures;
    }

    /**
     * Source declarations and loaded internal metadata establish complete inheritance edges.
     */
    private function hasUnknownAncestor(string $name, array $types, array $seen): bool
    {
        if (isset($seen[$name])) {
            return false;
        }

        $seen[$name] = true;
        if (! isset($types[$name])) {
            $loaded = class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false);

            return ! $loaded || ! (new \ReflectionClass($name))->isInternal();
        }

        $type = $types[$name];
        foreach ([...($type['parents'] ?? []), ...($type['traitNames'] ?? [])] as $parent) {
            if ($this->hasUnknownAncestor(strtolower($parent), $types, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identify this check in configuration and diagnostics.
     */
    public function id(): string
    {
        return 'no_unused_parameters';
    }

    /**
     * Retain scalar input candidates and inheritance signatures for the project pass.
     */
    public function requiredAnalyses(): array
    {
        return ['parameterInputs', 'classBehavior'];
    }
}
