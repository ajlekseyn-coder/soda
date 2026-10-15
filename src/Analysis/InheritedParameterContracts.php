<?php

declare(strict_types=1);

namespace Cosmira\Soda\Analysis;

use Cosmira\Soda\Analysis\Composition\FactoryDispatchPatterns;
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
    /**
     * Method name reserved by PHP for object construction contracts.
     */
    private const CONSTRUCTOR = '__construct';

    /**
     * Store signatures and parent edges without retaining AST nodes.
     */
    public static function collect(array $nodes): array
    {
        $types = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
            $name = $class->name === null ? CallableIdentity::type($class) : ($class->namespacedName?->toString() ?? $class->name->toString());
            $name = strtolower($name);
            $types[$name] = self::declaration($class);
        }

        return $types;
    }

    /**
     * Summarize one class-like declaration as scalar facts without retaining nodes.
     */
    private static function declaration(Stmt\ClassLike $class): array
    {
        $methods = [];
        foreach ($class->getMethods() as $method) {
            $methods[strtolower($method->name->toString())] = self::methodSignature($class, $method);
        }

        $parentNames = array_map(static fn (Name $parent): string => $parent->toString(), self::parents($class));

        return [
            'parentClass'     => self::parentClass($class),
            'traitNames'      => self::traitNames($class),
            'parentNames'     => $parentNames,
            'parents'         => array_map(strtolower(...), $parentNames),
            'methods'         => $methods,
            'factoryDispatch' => FactoryDispatchPatterns::collect($class),
        ];
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
            'private'             => $method->isPrivate(),
            'constructorContract' => $class instanceof Stmt\Interface_ || $method->isAbstract(),
            'forwardsParent'      => ForwardedParentConstructor::doesForward($method),
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
     * Distinguish a concrete parent edge from implemented interface contracts.
     */
    private static function parentClass(Stmt\ClassLike $class): ?string
    {
        return $class instanceof Stmt\Class_ ? $class->extends?->toLowerString() : null;
    }

    /**
     * Index only declarations from this analysis, never autoload application code.
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

        return $this->inherited(strtolower($class), strtolower($method), []);
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
        $declaration = $this->types[$type] ?? [];
        $methods = $declaration['methods'] ?? [];
        if (isset($methods[$method])) {
            return $methods[$method];
        }

        $matches = [];
        foreach ($declaration['traitNames'] ?? [] as $trait) {
            $signature = $this->signature(strtolower($trait), $method, $seen);
            if ($signature !== []) {
                $matches[] = $signature;
            }
        }

        return count($matches) === 1 ? reset($matches) : [];
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
