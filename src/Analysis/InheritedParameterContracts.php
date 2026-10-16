<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use Cosmira\Soda\Analysis\Composition\FactoryDispatchPatterns;
use Cosmira\Soda\Analysis\Usage\FactoryParameterDispatch;
use Cosmira\Soda\Analysis\Usage\ParameterUseAnalysis;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Resolves mandatory inherited parameter positions across analyzed source files.
 */
final readonly class InheritedParameterContracts
{
    use FactoryDispatchPatterns, FactoryParameterDispatch;

    /**
     * Method name reserved by PHP for object construction contracts.
     */
    private const CONSTRUCTOR = '__construct';

    /**
     * Every signature packet counts all declared positional entries, including optional ones.
     */
    private const ARITY = 'arity';

    /**
     * Store signatures and parent edges without retaining AST nodes.
     */
    public static function collect(array $nodes): array
    {
        $types = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
            $name = $class->name === null ? CallableIdentity::type($class) : ($class->namespacedName?->toString() ?? $class->name->toString());
            $name = strtolower($name);
            $types[$name] = self::declaration($class, $nodes) + ['ambiguous' => isset($types[$name])];
        }

        return $types;
    }

    /**
     * Summarize one class-like declaration as scalar facts without retaining nodes.
     */
    private static function declaration(Stmt\ClassLike $class, array $nodes): array
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[strtolower($method->name->toString())] = self::methodSignature($class, $method);
        }

        $parentNames = array_map(static fn (Name $parent): string => $parent->toString(), self::parents($class));

        return [
            'isInterface'       => $class instanceof Stmt\Interface_,
            'isTrait'           => $class instanceof Stmt\Trait_,
            'traitAliases'      => self::traitAliases($class),
            'traitExclusions'   => self::traitExclusions($class),
            'hasProperties'     => $class->getProperties() !== [],
            'parentClass'       => self::parentClass($class),
            'traitNames'        => self::traitNames($class),
            'parentNames'       => $parentNames,
            'parents'           => array_map(strtolower(...), $parentNames),
            'methods'           => $methods,
            'factoryDispatch'   => self::factoryPatterns($class),
            'parameterDispatch' => self::parameterDispatch($class, $nodes),
            'fieldFlows'        => self::fieldFlows($class),
            'taskLists'         => self::taskLists($class),
            'taskDispatch'      => self::taskDispatch($class),
        ];
    }

    /**
     * Late static binding can select a descendant's same-named method even when this declaration is private.
     */
    private static function canVaryByReceiver(Stmt\ClassLike $class, string $name, bool $lateBound): bool
    {
        $target = $class->getMethod($name);
        if ($target === null) {
            return false;
        }

        if ($class instanceof Stmt\Trait_) {
            return true;
        }

        $open = $class instanceof Stmt\Class_ && ! $class->isFinal();

        return $open && ($target->isPrivate() ? $lateBound : ! $target->isFinal());
    }

    /**
     * A trait's self reference binds to the consuming class, which can replace its methods.
     */
    private static function isReplaceableClassCall(?Node $owner, Stmt\ClassLike $class): bool
    {
        if (! $owner instanceof Name) {
            return false;
        }

        $name = $owner->toLowerString();

        return $name === 'static' || ($class instanceof Stmt\Trait_ && $name === 'self');
    }

    /**
     * Collect direct ancestors, distinguishing interface extension from class inheritance.
     *
     * @return list<Name>
     */
    private static function parents(Stmt\ClassLike $class): array
    {
        if ($class instanceof Stmt\Interface_) {
            return $class->extends;
        }

        if ($class instanceof Stmt\Class_) {
            return [...($class->extends instanceof Name ? [$class->extends] : []), ...$class->implements];
        }

        return [];
    }

    /**
     * Summarize one method signature, including which inputs its body reads.
     */
    private static function methodSignature(Stmt\ClassLike $class, Stmt\ClassMethod $method): array
    {
        return [
            'arity'               => count($method->params),
            'required'            => count(array_filter($method->params, static fn (Node\Param $input): bool => $input->default === null && ! $input->variadic)),
            'private'             => $method->isPrivate(),
            'abstract'            => $method->isAbstract(),
            'constructorContract' => $class instanceof Stmt\Interface_ || $method->isAbstract(),
            'forwardsParent'      => self::doesForwardConstructor($method),
            'positional'          => ! $method->isStatic() && array_filter($method->params, static fn (Node\Param $input): bool => $input->byRef || $input->variadic) === [],
            'reads'               => array_keys(array_filter($method->params, static fn (Node\Param $input): bool => (new ParameterUseAnalysis($input))->isUsed($method))),
        ];
    }

    /**
     * Collect the plain names of traits imported by this declaration.
     *
     * @return list<string>
     */
    private static function traitNames(Stmt\ClassLike $class): array
    {
        $traitNames = [];
        foreach ($class->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $traitNames[] = $trait->toString();
            }
        }

        return $traitNames;
    }

    /**
     * Excluding a concrete trait body cannot discharge an abstract requirement exported in its place.
     */
    private static function traitExclusions(Stmt\ClassLike $class): array
    {
        $excluded = [];
        foreach ($class->getTraitUses() as $use) {
            foreach ($use->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    $name = $adaptation->method->toLowerString();
                    $excluded[$name] = ($excluded[$name] ?? []) + array_fill_keys(array_map(static fn (Name $trait): string => $trait->toLowerString(), $adaptation->insteadof), true);
                }
            }
        }

        return $excluded;
    }

    /**
     * Distinguish a concrete parent edge from implemented interface contracts.
     */
    private static function parentClass(Stmt\ClassLike $class): ?string
    {
        return $class instanceof Stmt\Class_ ? $class->extends?->toLowerString() : null;
    }

    /**
     * Index source declarations without autoloading application code.
     *
     * @param array $types Declared type and inheritance facts used to resolve local contracts.
     */
    public function __construct(private array $types) {}

    /**
     * A declared ancestor signature protects only its own existing positions.
     */
    public function arity(?string $class, ?string $method): int
    {
        if ($class === null || $method === null) {
            return 0;
        }

        $owner = strtolower($class);
        $name = strtolower($method);

        return max($this->inherited($owner, $name, []), $this->traitRequirement($owner, $name));
    }

    /**
     * A transparent constructor adapter preserves only the actual parent's arity.
     */
    public function forwardedConstructorArity(?string $class): int
    {
        $declaration = $this->types[strtolower($class ?? '')] ?? [];
        $methods = $declaration['methods'] ?? [];
        $constructor = $methods[self::CONSTRUCTOR] ?? [];
        if (! ($constructor['forwardsParent'] ?? false)) {
            return 0;
        }

        $parent = $declaration['parentClass'] ?? null;
        $seen = [];
        while ($parent !== null && ! isset($seen[$parent])) {
            $seen[$parent] = true;
            $signature = $this->signature($parent, self::CONSTRUCTOR, []);
            if ($signature !== []) {
                return ($signature['private'] ?? true) ? 0 : $signature['arity'];
            }

            $declaration = $this->types[$parent] ?? [];
            $parent = $declaration['parentClass'] ?? null;
        }

        return 0;
    }

    /**
     * A base implementation may ignore input used by a polymorphic implementation.
     */
    public function isUsedByOverride(?string $class, ?string $method, int $position): bool
    {
        if ($class === null || $method === null || strtolower($method) === self::CONSTRUCTOR) {
            return false;
        }

        $owner = strtolower($class);
        $name = strtolower($method);
        $ownType = $this->types[$owner] ?? [];
        $ownMethods = $ownType['methods'] ?? [];
        $ownSignature = $ownMethods[$name] ?? [];
        if ($ownSignature['private'] ?? true) {
            return false;
        }

        foreach ($this->types as $typeName => $type) {
            $signature = ($type['methods'] ?? [])[$name] ?? [];
            $readsInput = ! ($signature['private'] ?? true) && in_array($position, $signature['reads'] ?? [], true);
            if ($readsInput && $this->hasAncestor($typeName, $owner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Follow real inheritance edges rather than matching unrelated method names.
     */
    private function hasAncestor(string $type, string $ancestor): bool
    {
        $pending = [$type];
        $visited = [];
        while ($pending !== []) {
            $name = array_pop($pending);
            if (isset($visited[$name])) {
                continue;
            }

            $visited[$name] = true;
            $declaration = $this->types[$name] ?? [];
            $parents = $declaration['parents'] ?? [];
            if (in_array($ancestor, $parents, true)) {
                return true;
            }

            array_push($pending, ...$parents);
        }

        return false;
    }

    /**
     * Find an ancestor's effective declaration, including its imported trait methods.
     */
    private function signature(string $type, string $method, array $seen): array
    {
        if (isset($seen[$type])) {
            return [];
        }

        $seen[$type] = true;
        if (! isset($this->types[$type])) {
            return $this->nativeSignature($type, $method);
        }

        $declaration = $this->types[$type] ?? [];
        $methods = $declaration['methods'] ?? [];
        if (isset($methods[$method])) {
            return $methods[$method];
        }

        $matches = $this->importedTraitSignatures($declaration, $method, $seen, true);
        $concrete = array_filter($matches, static fn (array $signature): bool => ! ($signature['abstract'] ?? false));
        if ($concrete !== []) {
            return count($concrete) === 1 ? reset($concrete) : [];
        }

        return $matches === [] ? [] : $this->abstractSignature($matches);
    }

    /**
     * Loaded internal types expose PHP contracts without invoking an application autoloader.
     */
    private function nativeSignature(string $type, string $method): array
    {
        if (! class_exists($type, false) && ! interface_exists($type, false)) {
            return [];
        }

        $native = new \ReflectionClass($type);
        if (! $native->isInternal() || ! $native->hasMethod($method)) {
            return [];
        }

        $signature = $native->getMethod($method);

        return [
            'arity'               => $signature->getNumberOfParameters(),
            'private'             => $signature->isPrivate(),
            'constructorContract' => $native->isInterface() || $signature->isAbstract(),
        ];
    }

    /**
     * Abstract imports constrain an implementing body even when that body's class has its own declaration.
     */
    private function traitRequirement(string $class, string $method): int
    {
        $signatures = $this->importedTraitSignatures($this->types[$class] ?? [], $method, []);
        $required = array_filter($signatures, static fn (array $signature): bool => $signature['abstract'] ?? false);

        return max([0, ...array_column($required, self::ARITY)]);
    }

    /**
     * An intermediate trait's concrete implementation discharges its exported abstract requirement.
     */
    private function traitSignature(string $name, string $method, array $seen): array
    {
        $type = $this->types[$name] ?? [];
        if (isset($seen[$name]) || ! ($type['isTrait'] ?? false)) {
            return [];
        }
        $seen[$name] = true;
        $methods = $type['methods'] ?? [];
        if (isset($methods[$method])) {
            return $methods[$method];
        }
        $signatures = $this->importedTraitSignatures($type, $method, $seen, true);
        $concrete = array_filter($signatures, static fn (array $signature): bool => ! ($signature['abstract'] ?? false));
        if ($concrete !== []) {
            return reset($concrete);
        }

        return $signatures === [] ? [] : $this->abstractSignature($signatures);
    }

    /**
     * Renamed imports retain their original use group's sources instead of borrowing another group's method.
     */
    private function importedTraitSignatures(array $type, string $method, array $seen, bool $selected = false): array
    {
        $signatures = [];
        foreach ($this->traitImports($type, $method, $selected) as $import) {
            foreach ($import['traits'] as $trait) {
                $signature = $this->traitSignature(strtolower($trait), $import['method'], $seen);
                $excluded = $import['excluded'] ?? [];
                $isExcludedBody = isset($excluded[strtolower($trait)]) && ! ($signature['abstract'] ?? false);
                if ($signature !== [] && ! $isExcludedBody) {
                    $signatures[] = $this->adaptedTraitSignature($signature, $import['modifier'] ?? null);
                }
            }
        }

        return $signatures;
    }

    /**
     * Visibility-only adaptations replace the original import, while a new alias keeps both names.
     */
    private function traitImports(array $type, string $method, bool $selected): array
    {
        $traits = array_map(strtolower(...), $type['traitNames'] ?? []);
        $excluded = $selected ? ($type['traitExclusions'] ?? [])[$method] ?? [] : [];
        $imports = [];
        foreach (($type['traitAliases'] ?? [])[$method] ?? [] as $alias) {
            if (! $alias['renamed']) {
                $traits = array_diff($traits, $alias['traits']);
                $alias['excluded'] = $excluded;
            }
            $imports[] = $alias;
        }
        $imports[] = ['traits' => $traits, 'method' => $method, 'excluded' => $excluded];

        return $imports;
    }

    /**
     * Inheritance sees the alias's visibility rather than the original trait method's visibility.
     */
    private function adaptedTraitSignature(array $signature, ?int $modifier): array
    {
        $visibility = Stmt\Class_::MODIFIER_PUBLIC | Stmt\Class_::MODIFIER_PROTECTED | Stmt\Class_::MODIFIER_PRIVATE;
        if ($modifier !== null && ($modifier & $visibility) !== 0) {
            $signature['private'] = ($modifier & Stmt\Class_::MODIFIER_PRIVATE) !== 0;
        }

        return $signature;
    }

    /**
     * Combined abstract declarations require their widest signature and remain private only when all are private.
     */
    private function abstractSignature(array $signatures): array
    {
        return [
            'arity'    => max(array_column($signatures, self::ARITY)),
            'private'  => ! in_array(false, array_column($signatures, 'private'), true),
            'abstract' => true, 'constructorContract' => true,
        ];
    }

    /**
     * Traverse inherited contracts with cycle protection for incomplete source.
     */
    private function inherited(string $class, string $method, array $visited): int
    {
        if (isset($visited[$class])) {
            return 0;
        }

        $visited[$class] = true;
        $arity = 0;
        $declaration = $this->types[$class] ?? [];
        foreach ($declaration['parents'] ?? [] as $parent) {
            $signature = $this->signature($parent, $method, []);
            $isContract = ! ($signature['private'] ?? true);
            if ($method === self::CONSTRUCTOR) {
                $isContract = $isContract && ($signature['constructorContract'] ?? false);
            }

            if ($isContract) {
                $arity = max($arity, $signature['arity']);
            }

            $arity = max($arity, $this->inherited($parent, $method, $visited));
        }

        return $arity;
    }
}
