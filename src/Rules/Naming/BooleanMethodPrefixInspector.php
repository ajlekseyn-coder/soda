<?php

declare(strict_types=1);

namespace Cosmira\Soda\Rules\Naming;

use function array_fill_keys;
use function array_values;

use Cosmira\Soda\Analysis\ProjectFacts;

use function is_array;
use function is_string;
use function str_starts_with;

/**
 * @internal
 */
final readonly class BooleanMethodPrefixInspector
{
    /**
     * Method names and qualified method names explicitly ignored by the rule.
     *
     * @var array<string, true>
     */
    private array $ignored;

    /**
     * Declared methods and inherited type names used to resolve naming contracts.
     *
     * @var array<string, array{inherits: list<string>, methods: array<string, true>}>
     */
    private array $typeIndex;

    /**
     * Build the project inheritance index and configure boolean-method naming exceptions.
     *
     * @param list<string> $ignore
     * @param list<string> $allowedPrefixes
     */
    public function __construct(
        ProjectFacts $project,
        array $ignore,
        /**
         * Predicate prefixes permitted for boolean-returning methods.
         */
        private array $allowedPrefixes,
    ) {
        $this->ignored = array_fill_keys($ignore, true);
        $this->typeIndex = $this->typeIndex($project);
    }

    /**
     * Select boolean-returning methods that violate the configured naming policy.
     *
     * @param array<string, mixed> $metrics
     *
     * @return list<array<string, mixed>>
     */
    public function violations(array $metrics): array
    {
        $badMethods = [];

        foreach ($this->methodsFromMetrics($metrics) as $methodData) {
            if ($this->shouldReportMethod($methodData)) {
                $badMethods[] = $methodData;
            }
        }

        return $badMethods;
    }

    /**
     * Extract method naming facts from the collected file metrics.
     *
     * @param array<string, mixed> $metrics
     *
     * @return list<array<string, mixed>>
     */
    private function methodsFromMetrics(array $metrics): array
    {
        $naming = $metrics['naming'] ?? null;
        $methods = is_array($naming) ? ($naming['methods'] ?? null) : null;

        return is_array($methods) ? $methods : [];
    }

    /**
     * Exclude allowed names and inherited contracts before flagging a boolean method.
     *
     * @param array<string, mixed> $methodData
     */
    private function shouldReportMethod(array $methodData): bool
    {
        $isBooleanMethod = $this->isBooleanClassMethod($methodData);
        if (! $isBooleanMethod) {
            return false;
        }

        $methodName = $methodData['methodName'];

        return ! $this->hasAllowedPrefix($methodName)
            && ! $this->isExcepted($methodData)
            && ! $this->isInheritedContract($methodData);
    }

    /**
     * Recognize boolean-returning class methods in the collected naming facts.
     *
     * @param array<string, mixed> $methodData
     */
    private function isBooleanClassMethod(array $methodData): bool
    {
        return ($methodData['class'] ?? null) !== null
            && $this->isBooleanReturnType($methodData['returnType'] ?? null)
            && is_string($methodData['methodName'] ?? null);
    }

    /**
     * Determine whether boolean return type applies to the supplied input.
     */
    private function isBooleanReturnType(mixed $returnType): bool
    {
        return is_string($returnType)
            && in_array('bool', explode('|', $returnType), true);
    }

    /**
     * Check the supplied input for allowed prefix.
     */
    private function hasAllowedPrefix(string $methodName): bool
    {
        foreach ($this->allowedPrefixes as $prefix) {
            if (str_starts_with($methodName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match ignored method names and explicitly allowed naming prefixes.
     *
     * @param array<string, mixed> $methodData
     */
    private function isExcepted(array $methodData): bool
    {
        $methodName = $methodData['methodName'] ?? null;
        $fullName = $methodData['name'] ?? null;

        return (is_string($methodName) && isset($this->ignored[$methodName]))
            || (is_string($fullName) && isset($this->ignored[$fullName]));
    }

    /**
     * Preserve method names required by an inherited declaration.
     *
     * @param array<string, mixed> $methodData
     */
    private function isInheritedContract(array $methodData): bool
    {
        $isOverride = ($methodData['hasOverrideAttribute'] ?? false) === true;
        if ($isOverride) {
            return true;
        }

        $className = $methodData['class'] ?? null;
        $methodName = $methodData['methodName'] ?? null;

        return is_string($className)
            && is_string($methodName)
            && $this->hasDeclaredMethod($className, $methodName);
    }

    /**
     * Index declared methods and inherited types across all analyzed files.
     *
     * @return array<string, array{inherits: list<string>, methods: array<string, true>}>
     */
    private function typeIndex(ProjectFacts $project): array
    {
        $index = [];

        foreach ($project->files as $metrics) {
            $naming = $metrics['naming'] ?? null;
            $types = is_array($naming) ? ($naming['types'] ?? null) : null;

            if (! is_array($types)) {
                continue;
            }

            foreach ($types as $typeData) {
                $entry = $this->typeIndexEntry($typeData);

                if ($entry === null) {
                    continue;
                }

                $index[$entry['name']] = $entry['data'];
            }
        }

        return $index;
    }

    /**
     * Normalize one type declaration into its methods and inheritance entry.
     *
     * @return array{name: string, data: array{inherits: list<string>, methods: array<string, true>}}|null
     */
    private function typeIndexEntry(mixed $typeData): ?array
    {
        if (! is_array($typeData)) {
            return null;
        }

        $name = $typeData['name'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        return [
            'name' => $name,
            'data' => [
                'inherits' => $this->stringList($typeData['inherits'] ?? []),
                'methods'  => array_fill_keys($this->stringList($typeData['methods'] ?? []), true),
            ],
        ];
    }

    /**
     * Retain only string entries when normalizing collected type facts.
     *
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));
    }

    /**
     * Find a method contract in the type or its ancestors while avoiding inheritance cycles.
     */
    private function hasDeclaredMethod(string $typeName, string $methodName): bool
    {
        $visited = [$typeName => true];
        $typeRow = $this->typeIndex[$typeName] ?? [];
        $pending = $typeRow['inherits'] ?? [];

        while ($pending !== []) {
            $ancestor = array_pop($pending);
            if (! is_string($ancestor) || isset($visited[$ancestor])) {
                continue;
            }

            $visited[$ancestor] = true;
            $ancestorType = $this->typeIndex[$ancestor] ?? null;

            if ($ancestorType === null) {
                continue;
            }

            $ancestorMethods = $ancestorType['methods'];
            if (isset($ancestorMethods[$methodName])) {
                return true;
            }

            $pending = [...$pending, ...$ancestorType['inherits']];
        }

        return false;
    }
}
