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
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

/**
 * Reports unused inputs while preserving positions required by known parent contracts.
 */
final class NoUnusedParameters extends Check
{
    /**
     * Composed visibility values retain PHP's public and private storage boundaries.
     */
    private const PUBLIC_VISIBILITY = 'public';

    /**
     * Private storage remains accessible only to methods bound to its declaring scope.
     */
    private const PRIVATE_VISIBILITY = 'private';

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
        $declared = (new NodeFinder)->findInstanceOf($file->nodes, Function_::class);
        $functions = array_fill_keys(array_map(static fn (Function_ $function): string => strtolower($function->namespacedName?->toString() ?? $function->name->toString()), $declared), true);
        yield from $this->findings(
            $file->path, ParameterInputFacts::collect($file->nodes), $inherited,
            LaravelMiddlewareContracts::collect($file->nodes), $this->composedSignatures($types, $surfaces, $inherited, $functions),
        );
    }

    /**
     * Resolve contracts after every file's declarations have been collected.
     */
    public function checkProject(ProjectFacts $project): iterable
    {
        $types = [];
        $middleware = [];
        $functions = [];
        foreach ($project->files as $path => $facts) {
            $types += $this->fileContracts($path, $facts['parameterContracts'] ?? []);
            $middleware += $facts['registeredMiddleware'] ?? [];
            $functions += array_change_key_case($facts['methods']);
        }

        $types = ExternalParameterContracts::resolve($types, array_keys($project->files));
        $inherited = new InheritedParameterContracts($types);
        $surfaces = (new BehaviorComposition)->project($project);
        $signatures = $this->composedSignatures($types, $surfaces, $inherited, $functions);
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
    private function composedSignatures(array $types, array $surfaces, InheritedParameterContracts $contracts, array $functions): array
    {
        $signatures = [];
        foreach ($surfaces as $class => $surface) {
            foreach ($surface['methods'] as $name => $method) {
                $origin = $method['origin'];
                $signatures[$origin] = max($signatures[$origin] ?? 0, $contracts->arity($class, $name));
            }
        }

        foreach ($this->dispatchedSignatures($types, $surfaces, $functions) as $origin => $arity) {
            $signatures[$origin] = max($signatures[$origin] ?? 0, $arity);
        }

        return array_replace($signatures, $this->unresolvedSignatures($types, $surfaces));
    }

    /**
     * Observed calls constrain only public methods on the declared receiver family.
     */
    private function dispatchedSignatures(array $types, array $surfaces, array $functions): array
    {
        $signatures = [];
        $calls = array_merge(...array_map(static fn (array $type): array => $type['parameterDispatch'] ?? [], array_values($types)));
        foreach ($this->propertyDispatches($calls, $types, $surfaces, $functions) as $call) {
            if (array_intersect_key($functions, array_flip($call['guards'])) !== []) {
                continue;
            }
            foreach ($surfaces as $class => $surface) {
                foreach ($this->dispatchTargets($call, $class, $surface, $types) as $method) {
                    $origin = $method['origin'];
                    $signatures[$origin] = max($signatures[$origin] ?? 0, $call['arity']);
                }
            }
        }

        return $signatures;
    }

    /**
     * Trait protocols apply only where their caller and target bodies were selected.
     */
    private function dispatchTargets(array $call, string $class, array $surface, array $types): array
    {
        $methods = $surface['methods'];
        if (isset($call['caller'])) {
            if (! in_array($call['caller'], array_column($methods, 'origin'), true)) {
                return [];
            }

            return array_filter($methods, static fn (array $method, string $name): bool => $method['visibility'] !== self::PRIVATE_VISIBILITY && preg_match($call['method'], $name) === 1, ARRAY_FILTER_USE_BOTH);
        }
        if (($call['pattern'] ?? false) && $this->isReceiverFamily($class, $call['type'], $types, [])) {
            return array_filter($methods, static fn (array $method, string $name): bool => $method['visibility'] === self::PUBLIC_VISIBILITY && preg_match($call['method'], $name) === 1, ARRAY_FILTER_USE_BOTH);
        }
        $method = $methods[$call['method']] ?? [];
        if (($method['visibility'] ?? '') !== self::PUBLIC_VISIBILITY || ! $this->isReceiverFamily($class, $call['type'], $types, [])) {
            return [];
        }

        return [$method];
    }

    /**
     * Native collaborator slots are resolved on the selected caller surface before matching receiver families.
     */
    private function propertyDispatches(array $calls, array $types, array $surfaces, array $functions): iterable
    {
        foreach ($calls as $call) {
            if (! isset($call['property'])) {
                yield $call;

                continue;
            }
            foreach ($surfaces as $surface) {
                $callers = array_filter($surface['methods'], static fn (array $method): bool => $method['origin'] === $call['caller']);
                $caller = array_shift($callers);
                if ($caller === null) {
                    continue;
                }
                $bound = $this->propertyBound($call['property'], $surface, $types, $functions, $caller);
                if ($bound === null) {
                    continue;
                }
                $source = $this->openSourceBound($bound['source'], $surfaces, $types);
                if ($source !== null && $this->isReceiverFamily($source, $bound['type'], $types, [])) {
                    yield array_replace($call, ['caller' => null, 'type' => $source, 'pattern' => true]);
                }
            }
        }
    }

    /**
     * One supported public constructor store must own the collaborator's native storage slot.
     */
    private function propertyBound(string $field, array $surface, array $types, array $functions, array $caller): ?array
    {
        $bound = ($surface['propertyTypes'] ?? [])[$field] ?? null;
        if ($bound === null || ! $this->isKnownField($field, $surface, $caller)) {
            return null;
        }
        $store = $this->stableFieldStore($field, $surface, $types, $functions) ?? [];
        $methods = $surface['methods'];
        $constructor = $methods['__construct'] ?? [];
        $source = $store['source'] ?? null;

        if (($constructor['visibility'] ?? '') !== self::PUBLIC_VISIBILITY || ($store['origin'] ?? null) !== ($constructor['origin'] ?? null) || ! ($store['constructor'] ?? false)) {
            return null;
        }
        if (! $this->isWiringCompatible($store['prefix'] ?? [], $surface, $constructor, $types)) {
            return null;
        }

        return $source === null ? null : ['type' => $bound, 'source' => $source];
    }

    /**
     * Earlier field types must accept each original input family without narrowing its possible overrides.
     */
    private function isWiringCompatible(array $prefix, array $surface, array $constructor, array $types): bool
    {
        foreach ($prefix as $field => $input) {
            $native = ($surface['propertyTypes'] ?? [])[$field] ?? null;
            if ($native === null || ! $this->isKnownField($field, $surface, $constructor) || ! $this->isReceiverFamily($input, $native, $types, [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Selected writers must identify one storage slot without unknown rebinding or reference escapes.
     */
    private function stableFieldStore(string $field, array $surface, array $types, array $functions): ?array
    {
        $stores = [];
        foreach ($surface['methods'] as $method) {
            $flow = $this->methodFlow($method, $types);
            $blocked = $flow['blocked'] ?? [];
            if (isset($blocked[$field]) || isset($blocked['*']) || array_intersect_key($functions, array_flip($flow['guards'] ?? [])) !== []) {
                return null;
            }
            $writes = $flow['writes'] ?? [];
            foreach ([...($writes[$field] ?? []), ...($writes['*'] ?? [])] as $store) {
                if (! $this->isKnownField($field, $surface, $method)) {
                    return null;
                }
                $stores[] = $store + ['origin' => $method['origin']];
            }
        }

        return count($stores) === 1 ? $stores[0] : null;
    }

    /**
     * Static, intercepted or redeclared fields cannot supply a stable native receiver identity.
     */
    private function isKnownField(string $field, array $surface, array $method): bool
    {
        $blocked = ($surface['blockedReads'] ?? []) + ($surface['ambiguousProperties'] ?? []);

        $properties = $surface['properties'] ?? [];
        $origins = $surface['propertyOrigins'] ?? [];
        $visible = ($properties[$field] ?? '') !== self::PRIVATE_VISIBILITY || ($origins[$field] ?? null) === $method['scope'];

        return isset($properties[$field]) && ! isset($blocked[$field]) && $visible;
    }

    /**
     * Selected method provenance indexes its original source facts, including trait aliases.
     */
    private function methodFlow(array $method, array $types): array
    {
        [$owner, $name] = explode('::', $method['origin'], 2);
        $flows = ($types[$owner] ?? [])['fieldFlows'] ?? [];

        return $flows[$name] ?? [];
    }

    /**
     * A pure public getter can return a native input supplied by a selected public setter.
     */
    private function openSourceBound(array $source, array $surfaces, array $types): ?string
    {
        if (! isset($source['getter'])) {
            return $source['type'];
        }
        $surface = $surfaces[$source['type']] ?? [];
        $getter = ($surface['methods'] ?? [])[$source['getter']] ?? [];
        if (($getter['visibility'] ?? '') !== self::PUBLIC_VISIBILITY) {
            return null;
        }
        $getterFlow = $this->methodFlow($getter, $types);
        $field = $getterFlow['return'] ?? null;
        if ($field === null || ! $this->isKnownField($field, $surface, $getter)) {
            return null;
        }

        return $this->openSetterBound($field, $surface, $types);
    }

    /**
     * A public setter retains only the native input family its selected body actually stores.
     */
    private function openSetterBound(string $field, array $surface, array $types): ?string
    {
        foreach ($surface['methods'] as $method) {
            if ($method['visibility'] !== self::PUBLIC_VISIBILITY || ! $this->isKnownField($field, $surface, $method)) {
                continue;
            }
            $flow = $this->methodFlow($method, $types);
            $writes = $flow['writes'] ?? [];
            foreach ($writes[$field] ?? [] as $store) {
                $input = $this->setterInput($store, $field, $surface, $types, count($writes[$field]) === 1);
                if ($input !== null) {
                    return $input;
                }
            }
        }

        return null;
    }

    /**
     * A readonly slot accepts constructor injection, while later setters cannot replace its initialized value.
     */
    private function setterInput(array $store, string $field, array $surface, array $types, bool $singleWrite): ?string
    {
        $readonly = $surface['readonly'] || (($surface['readonlyProperties'] ?? [])[$field] ?? false);
        if (! ($store['setter'] ?? false) || ($readonly && (! $store['constructor'] || ! $singleWrite))) {
            return null;
        }
        $input = $store['source'];
        $native = ($surface['propertyTypes'] ?? [])[$field] ?? null;
        $untyped = ($surface['untypedProperties'] ?? [])[$field] ?? false;

        return $untyped || ($native !== null && $this->isReceiverFamily($input['type'], $native, $types, [])) ? $input['type'] : null;
    }

    /**
     * Real inheritance edges distinguish a receiver family from same-named unrelated methods.
     */
    private function isReceiverFamily(string $class, string $bound, array $types, array $seen): bool
    {
        if ($class === $bound) {
            return true;
        }

        if (isset($seen[$class])) {
            return false;
        }

        $seen[$class] = true;
        foreach (($types[$class] ?? [])['parents'] ?? [] as $parent) {
            if ($this->isReceiverFamily($parent, $bound, $types, $seen)) {
                return true;
            }
        }

        return false;
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
                if ($signature['visibility'] !== self::PRIVATE_VISIBILITY) {
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
