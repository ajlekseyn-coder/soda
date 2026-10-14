<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Architecture;

use Cosmira\Soda\Analysis\ProjectFacts;
use Cosmira\Soda\Analysis\StronglyConnectedComponents;
use Cosmira\Soda\Contracts\Check;
use Cosmira\Soda\Reporting\Violation;

/**
 * Prohibits cycles between namespace modules, using actual resolved type dependencies.
 */
final class NoDependencyCycles extends Check
{
    /**
     * Configure the namespace prefixes that define dependency module boundaries.
     *
     * @param list<string> $modules Namespace prefixes; without them each exact namespace is a module.
     */
    public function __construct(
        /**
         * Namespace prefixes used to group class dependencies into modules.
         */
        private readonly array $modules = [],
    ) {}

    /**
     * Report mutually dependent modules using resolved local type dependencies.
     */
    public function checkProject(ProjectFacts $project): iterable
    {
        $classes = $this->classes($project);
        $dependencies = $this->dependencies($classes);
        foreach ((new StronglyConnectedComponents)->collect($dependencies['graph']) as $component) {
            if (count($component) >= 2) {
                yield $this->cycleViolation($component, $dependencies);
            }
        }
    }

    /**
     * Attach module ownership and declaration locations to resolved project types.
     */
    private function classes(ProjectFacts $project): array
    {
        $classes = [];
        foreach ($project->files as $file => $facts) {
            foreach ($facts['classes'] as $name => $class) {
                $classes[strtolower($name)] = $class + ['file' => $file, 'module' => $this->module($name), 'name' => $name];
            }
        }

        return $classes;
    }

    /**
     * Build module edges and retain one concrete type dependency proving each edge.
     */
    private function dependencies(array $classes): array
    {
        $graph = [];
        $edges = [];
        foreach ($classes as $class) {
            $from = $class['module'];
            if ($from === null) {
                continue;
            }

            $targets = $graph[$from] ?? [];
            $proofs = $edges[$from] ?? [];
            foreach ($class['depends_on'] ?? [] as $dependency) {
                $targetClass = $classes[strtolower($dependency)] ?? [];
                $to = $targetClass['module'] ?? null;
                if ($to === null || $from === $to) {
                    continue;
                }

                $targets[] = $to;
                $proofs[$to] ??= ['file' => $class['file'], 'line' => $class['line'], 'source' => $class['name'], 'target' => $dependency];
            }

            $graph[$from] = array_values(array_unique($targets));
            $edges[$from] = $proofs;
        }

        return ['graph' => $graph, 'edges' => $edges];
    }

    /**
     * Explain one real closed path while retaining the size of the complete component.
     */
    private function cycleViolation(array $component, array $dependencies): Violation
    {
        $cycle = StronglyConnectedComponents::cyclePath($dependencies['graph'], $component);
        $edges = $dependencies['edges'];
        $proofs = $edges[$cycle[0]];
        $location = $proofs[$cycle[1]];
        $evidence = [];
        foreach (array_slice($cycle, 1) as $index => $target) {
            $proofs = $edges[$cycle[$index]];
            $edge = $proofs[$target];
            $evidence[] = $edge['source'].' -> '.$edge['target'].' ('.$edge['file'].':'.$edge['line'].')';
        }

        return new Violation(
            rule: $this->id(), file: $location['file'],
            value: count($component), threshold: 0, line: $location['line'],
            message: 'Mutually dependent modules: '.implode(', ', $component)
                .'. Cycle: '.implode(' -> ', $cycle).'. Edges: '.implode('; ', $evidence)
                .'. Remove the dependency cycle across module boundaries.',
        );
    }

    /**
     * Select the longest configured namespace prefix, or the exact namespace by default.
     */
    private function module(string $class): ?string
    {
        if ($this->modules === []) {
            $separator = strrpos($class, '\\');

            return $separator === false ? '<global>' : strtolower(substr($class, 0, $separator));
        }

        $match = null;
        $qualified = strtolower($class);
        foreach ($this->modules as $module) {
            $prefix = strtolower(trim($module, '\\'));
            $isMoreSpecific = $match === null || strlen($prefix) > strlen($match);
            $namespacePrefix = $prefix.'\\';
            if (str_starts_with($qualified, $namespacePrefix) && $isMoreSpecific) {
                $match = $prefix;
            }
        }

        return $match;
    }

    /**
     * Identify this check in configuration and diagnostics.
     */
    public function id(): string
    {
        return 'no_dependency_cycles';
    }

    /**
     * Declare the fact collectors required by this check.
     */
    public function requiredAnalyses(): array
    {
        return ['structure', 'coupling'];
    }
}
