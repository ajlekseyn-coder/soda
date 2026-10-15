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
     * Store signatures and parent edges without retaining AST nodes.
     */
    public static function collect(array $nodes): array
    {
        $types = [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
            $name = $class->name === null ? CallableIdentity::type($class) : ($class->namespacedName?->toString() ?? $class->name->toString());
            $name = strtolower($name);
            $types[$name] = self::declaration($class, $nodes);
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
            'hasProperties'     => $class->getProperties() !== [],
            'parentClass'       => self::parentClass($class),
            'traitNames'        => self::traitNames($class),
            'parentNames'       => $parentNames,
            'parents'           => array_map(strtolower(...), $parentNames),
            'methods'           => $methods,
            'factoryDispatch'   => self::factoryPatterns($class),
            'parameterDispatch' => self::parameterDispatch($class, $nodes),
            'fieldFlows'        => self::fieldFlows($class),
        ];
    }

    /**
     * Nonlocal jumps can bypass an earlier lexical assignment.
     */
    private static function hasBindingJump(Stmt\ClassMethod $method): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Stmt\Goto_::class) as $jump) {
            if (self::isOwnedBy($jump, $method)) {
                return true;
            }
        }

        return false;
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
            'private'             => $method->isPrivate(),
            'constructorContract' => $class instanceof Stmt\Interface_ || $method->isAbstract(),
            'forwardsParent'      => self::doesForwardConstructor($method),
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
        if (! isset($this->types[$type])) {
            return $this->nativeSignature($type, $method);
        }

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
